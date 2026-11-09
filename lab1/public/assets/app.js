'use strict';

const byId = (id) => document.getElementById(id);
const state = { products: [], phrases: [], editing: null };
const money = new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' });
const ranges = { Elektronika: [50, 50000], 'Książki': [5, 500], 'Odzież': [10, 5000] };
const dateFormat = new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium', timeStyle: 'medium' });

function element(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

async function api(path, method = 'GET', body) {
  const response = await fetch(`/api/v1/${path}`, {
    method,
    headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = response.status === 204 ? null : await response.json();
  if (!response.ok) {
    const error = new Error(data?.message || 'Nie udało się wykonać operacji.');
    error.fields = data?.errors || {};
    throw error;
  }
  return data;
}

function notice(message, error = false) {
  byId('notice').textContent = message;
  byId('notice').className = error ? 'notice error' : 'notice';
  byId('notice').hidden = !message;
}

function showView(name) {
  document.querySelectorAll('.view').forEach((view) => { view.hidden = view.id !== `view-${name}`; });
  document.querySelectorAll('[data-view]').forEach((tab) => {
    const active = tab.dataset.view === name;
    tab.classList.toggle('active', active);
    if (active) tab.setAttribute('aria-current', 'page');
    else tab.removeAttribute('aria-current');
  });
}

function showErrors(error) {
  notice(error.message || 'Nie można połączyć się z aplikacją. Spróbuj odświeżyć dane.', true);
  for (const [field, message] of Object.entries(error.fields || {})) {
    const target = byId(`error-${field}`);
    if (target) {
      target.textContent = message;
      byId(field)?.setAttribute('aria-invalid', 'true');
    }
  }
}

function clearErrors(form) {
  form.querySelectorAll('.field-error').forEach((node) => { node.textContent = ''; });
  form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
}

async function busy(form, action) {
  const buttons = [...form.querySelectorAll('button')];
  buttons.forEach((button) => { button.disabled = true; });
  try { await action(); } catch (error) { showErrors(error); }
  finally { buttons.forEach((button) => { button.disabled = false; }); }
}

function updateRange() {
  const [min, max] = ranges[byId('category').value];
  byId('price').min = String(min);
  byId('price').max = String(max);
  byId('price-hint').textContent = `${min.toLocaleString('pl-PL')}–${max.toLocaleString('pl-PL')} PLN`;
}

function resetEditor() {
  state.editing = null;
  byId('product-form').reset();
  clearErrors(byId('product-form'));
  byId('form-title').textContent = 'Dodaj produkt';
  byId('form-description').textContent = 'Wypełnij dane nowego produktu.';
  byId('save-product').textContent = 'Dodaj produkt';
  byId('cancel-edit').hidden = true;
  updateRange();
}

function renderProducts() {
  const query = byId('search').value.trim().toLocaleLowerCase('pl-PL');
  const products = state.products.filter((product) => `${product.name} ${product.category}`.toLocaleLowerCase('pl-PL').includes(query));
  byId('product-count').textContent = String(state.products.length);
  byId('catalog-caption').textContent = `Liczba produktów: ${state.products.length}`;
  byId('product-rows').replaceChildren();
  for (const product of products) {
    const row = element('tr');
    const title = element('td');
    title.append(element('span', product.name, 'product-name'), element('span', `ID: ${product.id}`, 'product-id'));
    const category = element('td');
    category.append(element('span', product.category, 'category-badge'));
    const actions = element('td');
    const controls = element('div', undefined, 'row-actions');
    for (const [action, label] of [['edit', 'Edytuj'], ['history', 'Historia'], ['delete', 'Usuń']]) {
      const button = element('button', label, `text-button ${action}`);
      button.type = 'button';
      button.dataset.action = action;
      button.dataset.id = String(product.id);
      button.setAttribute('aria-label', `${label}: ${product.name}`);
      controls.append(button);
    }
    actions.append(controls);
    row.append(title, category, element('td', money.format(product.price), 'numeric'),
      element('td', `${product.quantity.toLocaleString('pl-PL')} szt.`, product.quantity === 0 ? 'numeric stock-zero' : 'numeric'), actions);
    byId('product-rows').append(row);
  }
  byId('products-empty').hidden = products.length > 0;
  byId('empty-title').textContent = state.products.length ? 'Nie znaleziono produktów' : 'Nie masz jeszcze produktów';
  byId('empty-description').textContent = state.products.length
    ? 'Zmień wpisaną nazwę lub kategorię, aby zobaczyć inne produkty.'
    : 'Dodaj pierwszy produkt w formularzu obok. Wszystkie zapisane produkty pojawią się tutaj.';
}

function renderPhrases() {
  byId('phrase-count').textContent = String(state.phrases.length);
  byId('phrase-list').replaceChildren();
  for (const phrase of state.phrases) {
    const item = element('li');
    const button = element('button', 'Usuń', 'text-button delete');
    button.type = 'button';
    button.dataset.id = String(phrase.id);
    button.setAttribute('aria-label', `Usuń frazę: ${phrase.phrase}`);
    item.append(element('span', phrase.phrase, 'phrase-text'), button);
    byId('phrase-list').append(item);
  }
  byId('phrases-empty').hidden = state.phrases.length > 0;
}

async function refresh() {
  const [products, phrases] = await Promise.all([api('products'), api('forbidden-phrases')]);
  state.products = products;
  state.phrases = phrases;
  renderProducts();
  renderPhrases();
  byId('connection').classList.remove('offline');
  byId('connection-text').textContent = 'Połączono';
}

function confirmRemoval(title, description) {
  byId('confirm-title').textContent = title;
  byId('confirm-description').textContent = description;
  const dialog = byId('confirm-dialog');
  dialog.returnValue = 'cancel';
  return new Promise((resolve) => {
    dialog.addEventListener('close', () => resolve(dialog.returnValue === 'delete'), { once: true });
    dialog.showModal();
  });
}

async function loadHistory(id) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Podaj poprawne, dodatnie ID produktu.');
  byId('history-id').value = String(id);
  byId('history-results').replaceChildren();
  const entries = await api(`products/${id}/history`);
  const events = element('div', undefined, 'history-events');
  for (const entry of entries) {
    const event = element('article', undefined, 'history-event');
    const heading = element('div', undefined, 'event-heading');
    const labels = { CREATE: 'Dodanie produktu', UPDATE: 'Edycja produktu', DELETE: 'Usunięcie produktu' };
    const time = element('time', dateFormat.format(new Date(entry.changedAt)));
    time.dateTime = entry.changedAt;
    heading.append(element('span', labels[entry.operation], `operation ${entry.operation}`), time);
    const table = element('table');
    const head = element('thead');
    const titles = element('tr');
    ['Pole', 'Przed zmianą', 'Po zmianie'].forEach((label) => titles.append(element('th', label)));
    head.append(titles);
    const body = element('tbody');
    for (const [field, label] of [['name', 'Nazwa'], ['category', 'Kategoria'], ['price', 'Cena'], ['quantity', 'Ilość']]) {
      const row = element('tr');
      const format = (value) => value === undefined ? '—' : field === 'price' ? money.format(value) : String(value);
      const before = entry.before?.[field];
      const after = entry.after?.[field];
      row.append(element('td', label), element('td', format(before)), element('td', format(after), before !== after ? 'changed' : ''));
      body.append(row);
    }
    table.append(head, body);
    const wrapper = element('div', undefined, 'table-wrap');
    wrapper.append(table);
    event.append(heading, wrapper);
    events.append(event);
  }
  byId('history-results').append(element('h3', `Produkt #${id} · zdarzenia: ${entries.length}`, 'history-title'), events);
}

document.querySelectorAll('[data-view]').forEach((tab) => tab.addEventListener('click', () => {
  showView(tab.dataset.view);
  notice('');
}));
byId('category').addEventListener('change', updateRange);
byId('search').addEventListener('input', renderProducts);
byId('cancel-edit').addEventListener('click', resetEditor);
byId('refresh').addEventListener('click', async () => {
  byId('refresh').disabled = true;
  try { await refresh(); notice('Dane zostały odświeżone.'); }
  catch (error) { showErrors(error); byId('connection').classList.add('offline'); byId('connection-text').textContent = 'Brak połączenia'; }
  finally { byId('refresh').disabled = false; }
});

byId('product-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  clearErrors(form);
  busy(form, async () => {
    const body = {
      name: byId('name').value,
      category: byId('category').value,
      price: byId('price').value === '' ? null : Number(byId('price').value),
      quantity: byId('quantity').value === '' ? null : Number(byId('quantity').value),
    };
    const editing = state.editing;
    await api(editing ? `products/${editing}` : 'products', editing ? 'PUT' : 'POST', body);
    resetEditor();
    await refresh();
    notice(editing ? 'Zmiany produktu zostały zapisane.' : 'Produkt został dodany do katalogu.');
  });
});

byId('product-rows').addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button || button.disabled) return;
  const id = Number(button.dataset.id);
  const product = state.products.find((item) => item.id === id);
  try {
    if (button.dataset.action === 'edit') {
      resetEditor();
      state.editing = id;
      for (const field of ['name', 'category', 'price', 'quantity']) byId(field).value = product[field];
      byId('form-title').textContent = 'Edytuj produkt';
      byId('form-description').textContent = `Zmieniasz produkt #${id}.`;
      byId('save-product').textContent = 'Zapisz zmiany';
      byId('cancel-edit').hidden = false;
      updateRange();
      notice('');
      byId('name').focus();
    } else if (button.dataset.action === 'history') {
      showView('history');
      notice('');
      await loadHistory(id);
    } else if (await confirmRemoval('Usunąć produkt?', `Produkt „${product.name}” zostanie usunięty z katalogu. Historia jego zmian pozostanie dostępna pod ID ${id}.`)) {
      button.disabled = true;
      await api(`products/${id}`, 'DELETE');
      if (state.editing === id) resetEditor();
      byId('history-id').value = String(id);
      await refresh();
      notice(`Produkt został usunięty. Jego historię znajdziesz pod ID ${id}.`);
    }
  } catch (error) { showErrors(error); }
  finally { button.disabled = false; }
});

byId('phrase-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  clearErrors(form);
  busy(form, async () => {
    await api('forbidden-phrases', 'POST', { phrase: byId('phrase').value });
    form.reset();
    await refresh();
    notice('Zabroniona fraza została dodana. Blokada jest już aktywna.');
  });
});

byId('phrase-list').addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-id]');
  if (!button || button.disabled) return;
  const phrase = state.phrases.find((item) => item.id === Number(button.dataset.id));
  if (!await confirmRemoval('Usunąć zabronioną frazę?', `Fraza „${phrase.phrase}” przestanie blokować nazwy produktów.`)) return;
  button.disabled = true;
  try {
    await api(`forbidden-phrases/${phrase.id}`, 'DELETE');
    await refresh();
    notice('Fraza została usunięta.');
  } catch (error) { showErrors(error); }
  finally { button.disabled = false; }
});

byId('history-form').addEventListener('submit', (event) => {
  event.preventDefault();
  busy(event.currentTarget, async () => { notice(''); await loadHistory(Number(byId('history-id').value)); });
});

updateRange();
refresh().catch((error) => {
  byId('connection').classList.add('offline');
  byId('connection-text').textContent = 'Brak połączenia';
  showErrors(error);
});
