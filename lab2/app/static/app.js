// Zwykły JavaScript: formularz i tabela korzystają z istniejącego API.
const $ = (id) => document.getElementById(id);
const statusNames = {pending: "Oczekujące", queued: "W kolejce", sent: "Dostarczone", failed: "Błąd końcowy", cancelled: "Anulowane"};
const pageSize = 50;
let offset = 0;
let loading = false;
let acting = false;
let selectedId = null;
let lastTable = "";
let settings = null;
let requestVersion = 0;
let inboxRecipient = "";
let lastInbox = "";

function showMessage(text, error = false) {
  const box = $("message");
  box.textContent = text;
  box.className = "message" + (error ? " error" : "");
  box.setAttribute("role", error ? "alert" : "status");
  box.hidden = false;
}

async function api(path, options = {}) {
  const response = await fetch(path, options);
  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    let detail = data.detail;
    if (Array.isArray(detail)) detail = detail.map((item) => `${item.loc.at(-1)}: ${item.msg}`).join("; ");
    throw new Error(typeof detail === "string" ? detail : `Błąd serwera (${response.status}). Spróbuj ponownie.`);
  }
  return response.json();
}

function post(path, data) {
  return api(path, {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify(data)});
}

function localInputValue(date) {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 19);
}

function formatDate(value, zone) {
  const options = {
    day: "2-digit", month: "2-digit", year: "numeric",
    hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23",
  };
  try {
    return new Intl.DateTimeFormat("pl-PL", {...options, timeZone: zone}).format(new Date(value));
  } catch {
    // Python i przeglądarka mogą mieć różne zestawy stref czasowych.
    return new Intl.DateTimeFormat("pl-PL", {...options, timeZone: "UTC"}).format(new Date(value)) + " UTC";
  }
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function badge(status) {
  return element("span", `badge ${status}`, statusNames[status] || status);
}

function actionButton(label, action, item, className = "") {
  const button = element("button", `action ${className}`, label);
  button.type = "button";
  button.dataset.action = action;
  button.dataset.id = item.id;
  button.setAttribute("aria-label", `${label}: ${item.recipient}`);
  if (action !== "history") button.disabled = !["pending", "queued"].includes(item.status);
  return button;
}

function renderTable(items) {
  const signature = JSON.stringify(items);
  if (signature === lastTable) return;
  const body = $("notifications");
  body.replaceChildren();
  if (!items.length) {
    const row = element("tr");
    const cell = element("td", "empty", "Brak powiadomień w tym widoku.\nZaplanuj pierwszą wiadomość w formularzu obok.");
    cell.colSpan = 4;
    row.append(cell);
    body.append(row);
    lastTable = signature;
    return;
  }
  for (const item of items) {
    const row = element("tr");
    const message = element("td");
    message.append(element("div", "recipient", item.recipient));
    message.append(element("div", "content-preview", item.content.length > 110 ? item.content.slice(0, 110) + "…" : item.content));
    const metadata = element("div", "metadata");
    metadata.append(element("span", "channel-tag", item.channel === "email" ? "E-mail" : "Push"));
    metadata.append(element("span", item.priority === "high" ? "priority-high" : "", item.priority === "high" ? "↑ Wysoki priorytet" : "Niski priorytet"));
    message.append(metadata);

    const schedule = element("td", "schedule");
    schedule.append(element("span", "", formatDate(item.scheduled_at, item.timezone)));
    schedule.append(element("span", "zone-name", item.timezone));
    if (new Date(item.scheduled_at) > new Date(item.requested_at)) {
      schedule.append(element("span", "shifted", "Termin przesunięty (cisza nocna lub ponowienie)"));
    }
    const status = element("td");
    status.append(badge(item.status));
    status.append(element("span", "attempt-count", `${item.attempts} / ${settings?.max_attempts || 3} prób`));
    const actions = element("td");
    const group = element("div", "actions");
    group.append(actionButton("Wyślij teraz", "send-now", item), actionButton("Anuluj", "cancel", item, "cancel"), actionButton("Historia prób", "history", item));
    actions.append(group);
    row.append(message, schedule, status, actions);
    body.append(row);
  }
  lastTable = signature;
}

function renderInbox(deliveries) {
  const signature = JSON.stringify(deliveries);
  if (signature === lastInbox) return;
  const list = $("inbox-list");
  list.replaceChildren();
  if (!deliveries.length) {
    list.append(element("p", "inbox-empty", "Brak odebranych wiadomości. Zaplanuj wysyłkę i poczekaj na jej dostarczenie."));
  }
  for (const delivery of deliveries) {
    const card = element("article", "received-message");
    card.dataset.notificationId = delivery.notification_id;
    const heading = element("div", "received-heading");
    heading.append(element("span", "badge sent", "Odebrano"), element("span", "channel-tag", delivery.channel === "email" ? "E-mail" : "Push"));
    card.append(heading, element("p", "recipient", delivery.recipient));
    card.append(element("p", "received-content", delivery.content));
    const time = element("time", "received-time", formatDate(delivery.delivered_at));
    time.dateTime = delivery.delivered_at;
    card.append(time, element("span", "zone-name", "Czas odbioru w strefie Twojej przeglądarki"));
    card.append(element("p", "received-id", `ID: ${delivery.notification_id}`));
    card.append(actionButton("Historia prób", "history", {id: delivery.notification_id, recipient: delivery.recipient}));
    list.append(card);
  }
  lastInbox = signature;
}

async function refresh(manual = false) {
  if (loading || acting) return;
  loading = true;
  const version = requestVersion;
  try {
    const filter = $("status-filter").value;
    const query = new URLSearchParams({limit: pageSize + 1, offset});
    if (filter) query.set("status", filter);
    const inboxQuery = new URLSearchParams({limit: 20});
    if (inboxRecipient) inboxQuery.set("recipient", inboxRecipient);
    if ($("inbox-channel").value) inboxQuery.set("channel", $("inbox-channel").value);
    const [rows, received] = await Promise.all([
      api(`/notifications?${query}`), api(`/deliveries?${inboxQuery}`),
    ]);
    // Zmiana filtra w czasie zapytania nie może wyświetlić starej odpowiedzi.
    if (version !== requestVersion) return;
    const items = rows.slice(0, pageSize);
    renderTable(items);
    renderInbox(received);
    $("inbox-state").textContent = `● Na żywo · ${received.length} ostatnich dostarczeń (maks. 20)`;
    $("stat-all").textContent = items.length;
    $("stat-waiting").textContent = items.filter((item) => ["pending", "queued"].includes(item.status)).length;
    $("stat-sent").textContent = items.filter((item) => item.status === "sent").length;
    $("stat-failed").textContent = items.filter((item) => item.status === "failed").length;
    $("previous-page").disabled = offset === 0;
    $("next-page").disabled = rows.length <= pageSize;
    $("page-info").textContent = `Strona ${offset / pageSize + 1} · ${items.length} wpisów`;
    $("connection").textContent = "● Połączono · " + new Date().toLocaleTimeString("pl-PL");
    $("connection").classList.remove("offline");
    if (selectedId && $("history-dialog").open) await loadHistory(selectedId);
  } catch (error) {
    $("inbox-state").textContent = "Brak połączenia — podgląd może być nieaktualny.";
    $("connection").textContent = "● Brak połączenia";
    $("connection").classList.add("offline");
    if (manual || !lastTable) showMessage(`Nie udało się odświeżyć danych. ${error.message}`, true);
  } finally {
    loading = false;
    if (version !== requestVersion) refresh();
  }
}

async function loadHistory(id) {
  const [item, attempts] = await Promise.all([api(`/notifications/${id}`), api(`/notifications/${id}/attempts`)]);
  if (selectedId !== id || !$("history-dialog").open) return;
  const detail = $("history-detail");
  detail.replaceChildren();
  detail.append(badge(item.status), element("p", "detail-text", item.content));
  const info = element("p", "detail-meta");
  for (const line of [
    `Odbiorca: ${item.recipient} · ${item.channel === "email" ? "E-mail" : "Push"}`,
    `Żądany termin: ${formatDate(item.requested_at, item.timezone)} (${item.timezone})`,
    `Termin wykonania: ${formatDate(item.scheduled_at, item.timezone)} (${item.timezone})`,
    `Identyfikator: ${item.id}`,
  ]) {
    info.append(document.createTextNode(line), element("br"));
  }
  detail.append(info);
  const history = $("history-attempts");
  history.replaceChildren();
  if (!attempts.length) history.append(element("p", "detail-meta", "Nie wykonano jeszcze żadnej próby."));
  for (const attempt of attempts) {
    const entry = element("div", "attempt-item");
    entry.append(badge(attempt.result), document.createTextNode(`Próba ${attempt.number} · ${formatDate(attempt.created_at, item.timezone)}`));
    if (attempt.error) entry.append(element("div", "", attempt.error));
    history.append(entry);
  }
}

$("notification-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  if (acting) return;
  const scheduled = new Date($("scheduled-at").value);
  if (!Number.isFinite(scheduled.getTime()) || scheduled <= new Date()) {
    showMessage("Wybierz termin w przyszłości. Możesz ponownie kliknąć „Wypełnij przykład”.", true);
    return;
  }
  acting = true;
  $("submit-button").disabled = true;
  try {
    const item = await post("/notifications", {
      recipient: $("recipient").value.trim(), content: $("content").value.trim(),
      channel: $("channel").value, priority: $("priority").value,
      timezone: $("timezone").value.trim(), scheduled_at: scheduled.toISOString(),
      simulate_failures: Number($("failures").value),
    });
    const moved = new Date(item.scheduled_at) > new Date(item.requested_at);
    showMessage(`Zaplanowano powiadomienie. Termin u odbiorcy: ${formatDate(item.scheduled_at, item.timezone)} (${item.timezone}).` + (moved ? " Termin przesunięto ze względu na ciszę nocną." : ""));
    offset = 0;
    $("status-filter").value = "";
    requestVersion++;
    $("scheduled-at").value = localInputValue(new Date(Date.now() + 60000));
  } catch (error) {
    showMessage(error.message, true);
  } finally {
    acting = false;
    $("submit-button").disabled = false;
    refresh();
  }
});

async function handleNotificationAction(event) {
  const button = event.target.closest("button[data-action]");
  if (!button || button.disabled || acting) return;
  const {id, action} = button.dataset;
  if (action === "history") {
    selectedId = id;
    $("history-detail").replaceChildren();
    $("history-attempts").textContent = "Wczytywanie historii…";
    $("history-dialog").showModal();
    try { await loadHistory(id); }
    catch (error) { $("history-attempts").textContent = error.message; }
    return;
  }
  acting = true;
  requestVersion++;
  button.disabled = true;
  try {
    const item = await post(`/notifications/${id}/${action}`, {});
    showMessage(action === "cancel" ? "Powiadomienie zostało anulowane." :
      `Zlecono wysyłkę w najbliższym dozwolonym terminie: ${formatDate(item.scheduled_at, item.timezone)} (${item.timezone}).`);
  } catch (error) {
    showMessage(error.message, true);
  } finally {
    acting = false;
    lastTable = "";
    refresh();
  }
}

$("notifications").addEventListener("click", handleNotificationAction);
$("inbox-list").addEventListener("click", handleNotificationAction);
$("inbox-filter").addEventListener("submit", (event) => {
  event.preventDefault();
  inboxRecipient = $("inbox-recipient").value.trim();
  requestVersion++;
  refresh(true);
});

function daytimeZone() {
  const hour = new Date().getUTCHours();
  const offset = hour - 12;
  return offset === 0 ? "Etc/GMT" : `Etc/GMT${offset > 0 ? "+" : ""}${offset}`;
}

$("fill-example").addEventListener("click", () => {
  $("recipient").value = "student@example.com";
  $("content").value = "Przypomnienie o prezentacji laboratorium 2.";
  $("scheduled-at").value = localInputValue(new Date(Date.now() + 15000));
  $("timezone").value = daytimeZone();
  showMessage("Uzupełniono przykład na za 15 sekund. Odbiorca jest w strefie, w której jest południe. Wybierz kanał i liczbę błędów, a następnie kliknij „Zaplanuj powiadomienie”.");
});

$("refresh").addEventListener("click", () => refresh(true));
$("status-filter").addEventListener("change", () => { offset = 0; requestVersion++; refresh(true); });
$("previous-page").addEventListener("click", () => { offset = Math.max(0, offset - pageSize); requestVersion++; refresh(true); });
$("next-page").addEventListener("click", () => { offset += pageSize; requestVersion++; refresh(true); });
$("close-history").addEventListener("click", () => $("history-dialog").close());
$("history-dialog").addEventListener("close", () => { selectedId = null; });

async function init() {
  const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  $("date-help").textContent = `Termin wpisujesz w czasie swojej przeglądarki: ${browserZone}.`;
  $("scheduled-at").value = localInputValue(new Date(Date.now() + 60000));
  try {
    settings = await api("/settings");
    const hour = (value) => String(value).padStart(2, "0") + ":00";
    $("quiet-help").textContent = settings.quiet_start === settings.quiet_end
      ? "Cisza nocna jest wyłączona."
      : `Cisza nocna: ${hour(settings.quiet_start)}–${hour(settings.quiet_end)} w strefie odbiorcy. Nocny termin zostanie przesunięty.`;
  } catch (error) {
    $("quiet-help").textContent = "Nie udało się wczytać godzin ciszy nocnej.";
    showMessage(error.message, true);
  }
  await refresh(true);
  setInterval(() => { if (!document.hidden) refresh(); }, 3000);
}

init();
