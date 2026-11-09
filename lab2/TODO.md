# Laboratorium 2 — plan realizacji

Źródło wymagań: [Laboratorium 2.pdf](Laboratorium%202.pdf), strony 1–2.
Strony 3–8 przedstawiają warianty technologii i przykładowe fragmenty kodu.
Rozwiązanie jest zaimplementowane. Instrukcja uruchomienia i scenariusz oddania: [README.md](README.md).
Całość rozwiązania tworzymy w folderze `lab2`.

## Wybrany stos

- **Python** — wybrany język.
- **FastAPI** — API do tworzenia i zarządzania powiadomieniami.
- **Celery** — zadania w tle, osobne procesy obsługujące e-mail i push oraz ponowienia.
- **RabbitMQ** — broker wiadomości i kolejki kanałów.
- **PostgreSQL + SQLAlchemy + Alembic** — trwały zapis danych, dostęp do bazy i migracje.

Harmonogram jest oparty na terminach zapisanych w bazie i cyklicznym przekazywaniu gotowych zadań do kolejek. Wersje zależności są zapisane w `requirements.txt`, a sposób uruchomienia w README.

## 1. Wymagania z laboratorium

- [x] Tworzenie powiadomienia z treścią, odbiorcą, kanałem (e-mail albo push) i strefą czasową.
- [x] Planowanie symulowanego dostarczenia na konkretną datę i godzinę w przyszłości.
- [x] Niezależna obsługa kanałów wysyłki na osobnych instancjach serwera.
- [x] Trwałe przechowywanie powiadomień do wysłania, również przy dużym obciążeniu.
- [x] Asynchroniczne, uporządkowane i niezawodne przetwarzanie niezależne od tworzenia powiadomień.
- [x] Dokładnie jedno symulowane dostarczenie danej wiadomości do danego odbiorcy: bez duplikatów i pominięć.
- [x] Obsługa trzech prób dostarczenia łącznie, z odstępem co najmniej 5 sekund.
- [x] Dwa priorytety: niski i wysoki; wyższy priorytet zwiększa szansę wcześniejszego dostarczenia.
- [x] Wymuszenie natychmiastowej wysyłki zaplanowanego powiadomienia.
- [x] Anulowanie zaplanowanej wysyłki.
- [x] Uwzględnianie lokalnej strefy odbiorcy i unikanie wysyłki w godzinach nocnych.

## 2. Decyzje przed implementacją

- [x] Wybrać język i stos: Python + FastAPI + Celery + RabbitMQ + PostgreSQL/SQLAlchemy; migracje Alembic, wykonanie ponowień przez Celery z terminami w bazie.
- [x] Wersje zależności w `requirements.txt`; Python 3.12 w Dockerze, osobne kontenery dla API, harmonogramu i każdego kanału.
- [x] Obsługa przez panel `/` (formularz, statusy, historia prób, anulowanie, wysłanie teraz), interaktywne API `/docs` i gotowy skrypt `scripts/demo.py`.
- [x] Trzy próby łącznie, odstęp co najmniej 5 sekund; licznik i terminy trwale w PostgreSQL, wykonanie przez Celery.
- [x] Cisza nocna 22:00–07:00; przesunięcie na najbliższą dozwoloną porę odbiorcy.
- [x] „Wyślij teraz” respektuje ciszę nocną.
- [x] API wymaga daty z offsetem, zapis w UTC; reguły ciszy przechodzą po osi UTC, uwzględniając zmianę czasu.
- [x] Po trzech błędach status `failed`, dane i historia prób pozostają w bazie.
- [x] Dokładnie jeden efekt symulacji w bazie dzięki transakcji, blokadzie wiersza i unikalnemu zapisowi; trwała awaria po limicie prób jest jawnie raportowana.

Uwaga z analizy przykładów: kod w PDF jest szkicem, nie kompletnym rozwiązaniem wymagań. Samo pole `sent` nie zabezpiecza przed duplikatami podczas awarii i równoległego przetwarzania. Przykłady Java/Python wskazują trzy próby łącznie, a przykład C# używa trzech ponowień; dlatego tę zasadę trzeba ujednolicić.

## 3. Projekt rozwiązania po wyborze stosu

Poniższe punkty to proponowane zadania techniczne wynikające z wymagań, a nie dodatkowe polecenia z PDF.

- [x] Rozdzielić przyjmowanie zleceń, harmonogram oraz procesy obsługujące e-mail i push; przygotować schemat przepływu.
- [x] Zaprojektować model powiadomienia: identyfikator, treść, odbiorca, kanał, strefa, priorytet, termin żądany i efektywny, status oraz licznik prób.
- [x] Zaprojektować historię prób i trwały zapis symulowanego dostarczenia.
- [x] Zdefiniować stany i dozwolone przejścia, w tym anulowanie, ponowienie i błąd końcowy.
- [x] Zaplanować trwały harmonogram i kolejki oraz odtwarzanie pracy po restarcie.
- [x] Zaplanować niezawodne przekazanie zlecenia z bazy do kolejki, np. przez transactional outbox lub równoważny mechanizm.
- [x] Zaprojektować idempotentne przetwarzanie: unikalny zapis dostarczenia dla wiadomości i odbiorcy, transakcje oraz potwierdzenie zadania po trwałym zapisie wyniku.
- [x] Rozwiązać wyścigi między wysyłką, anulowaniem i „wyślij teraz”; zabezpieczyć przed wykonaniem starego zadania po zmianie planu.
- [x] Preferencja wysokiego priorytetu w harmonogramie i RabbitMQ, tylko dla gotowych zadań. Przy stałym napływie wysokiego priorytetu niski może czekać dłużej — opisane w README.
- [x] Przechowywać momenty wykonania w UTC, zachowując identyfikator strefy odbiorcy i reguły godzin lokalnych.

## 4. Implementacja

- [x] Przygotować strukturę projektu, konfigurację i zależności w `lab2`.
- [x] Przygotować bazę danych, migracje oraz trwałe mechanizmy kolejkowania i planowania.
- [x] Zaimplementować tworzenie i odczyt powiadomień oraz walidację danych.
- [x] Zaimplementować wyznaczanie terminu z uwzględnieniem strefy i ciszy nocnej.
- [x] Zaimplementować harmonogram przekazujący gotowe zadania do odpowiedniego kanału.
- [x] Zaimplementować osobne instancje obsługujące e-mail i push.
- [x] Zaimplementować symulację dostarczenia i kontrolowane symulowanie błędów do demonstracji ponowień.
- [x] Dodać idempotencję, obsługę ponowień i trwały status błędu po wyczerpaniu limitu.
- [x] Dodać obsługę priorytetów, anulowania i „wyślij teraz”.
- [x] Dodać logi z identyfikatorem powiadomienia, kanałem, numerem próby i wynikiem.

## 5. Weryfikacja i demonstracja

- [x] Utworzenie i dostarczenie powiadomienia każdym kanałem w zaplanowanym terminie.
- [x] Działanie kanału push przy zatrzymanej instancji e-mail i odwrotnie.
- [x] Zachowanie zleceń po restarcie oraz obsługa większej liczby oczekujących zadań.
- [x] Ponowne otrzymanie tego samego zadania i równoległe przetwarzanie nie tworzą duplikatu dostarczenia.
- [x] Awaria przed i po zapisie wyniku oraz przed potwierdzeniem zadania nie powoduje pominięcia ani ponownego efektu dostarczenia.
- [x] Błąd przejściowy uruchamia ponowienia, a błąd trwały kończy się po ustalonym limicie prób.
- [x] Wysoki priorytet ma preferencję przy kolejce zadań gotowych do wykonania.
- [x] Anulowane zadanie nie jest dostarczane; „wyślij teraz” nie powoduje drugiej wysyłki w pierwotnym terminie.
- [x] Wyścigi anulowania i wymuszenia wysyłki z działającym procesem mają określony, spójny wynik.
- [x] Poprawne terminy dla różnych stref, ciszy nocnej i zmiany czasu.
- [x] Przygotować README z uruchomieniem wszystkich instancji, konfiguracją, opisem architektury, przyjętymi zasadami i scenariuszem prezentacji.

## Kolejność prac

1. Rozstrzygnięcie pozostałych zasad z sekcji 2 dla wybranego stosu Python.
2. Projekt modelu, stanów i przepływu wiadomości.
3. Pierwszy pełny przepływ: utworzenie → trwały zapis → termin → kanał → symulowane dostarczenie.
4. Niezawodność, ponowienia i odporność na duplikaty.
5. Priorytety, anulowanie, „wyślij teraz” i reguły godzin lokalnych.
6. Weryfikacja scenariuszy oraz dokumentacja uruchomienia.

## Wynik weryfikacji — 31.10.2026

- 31 testów na PostgreSQL zakończonych powodzeniem, w tym 250 zleceń, testy równoległości i filtrowanie skrzynki odbiorczej.
- Pełny scenariusz `scripts/test-system.ps1` zakończony powodzeniem: rzeczywiste API, Celery i RabbitMQ, oba kanały, ponowienia, anulowanie, wysłanie teraz, zatrzymanie kolejno obu workerów, restart bazy i brokera, brak utraty oraz duplikatów dostarczeń dla zleceń testowych.
- `alembic check`: brak różnic między migracją i modelami.
- Końcowy stan: wszystkie usługi uruchomione, `/health` zwraca OK, `/docs` zwraca HTTP 200.
- Panel `/` sprawdzony w Chromium: tworzenie, „wyślij teraz”, trzy próby, historia, anulowanie, filtr statusu oraz widok mobilny; brak błędów JavaScript.

## Poprawki po przeglądzie

- [x] Worker pobiera aktualną godzinę po uzyskaniu blokady wiersza, żeby nie ominąć początku ciszy nocnej podczas oczekiwania na bazę.
- [x] Panel pokazuje datę w UTC z oznaczeniem, jeśli przeglądarka nie zna strefy obsługiwanej przez Pythona; nietypowa strefa nie blokuje tabeli ani historii.
- [x] `/health` zwraca HTTP 503 z czytelnym komunikatem przy niedostępnej bazie.
- [x] Skrypty pokazowe sprawdzają dostarczenia po identyfikatorze, niezależnie od liczby innych wpisów w bazie.
- [x] README zawiera tabelę przypisującą wymagania z PDF do plików projektu.
- [x] Boczna skrzynka odbiorcza korzysta z `/deliveries`, pokazuje ostatnich 20 dostarczeń, filtruje odbiorcę i kanał, udostępnia historię i odświeża się co 3 sekundy.
- [x] Podgląd sprawdzony w przeglądarce przy zatrzymanym workerze e-mail: e-mail nie pojawia się w skrzynce, push działa; po uruchomieniu workera e-mail pojawia się dokładnie raz. Sprawdzone filtry i widok mobilny.
