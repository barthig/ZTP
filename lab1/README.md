# NZTP — laboratorium 1

REST API do zarządzania produktami, napisane w **PHP**. Realizuje zakres na ocenę 5,0 ze stron 21–24 pliku `NZTP_ 01 (1).pdf`: CRUD z bazą danych, walidację domenową, dynamiczne zabronione frazy, architekturę N-Layer, trwałą historię zmian oraz testy integracyjne przez HTTP.

## Uruchomienie w Dockerze

Wymagany Docker Desktop z uruchomionym silnikiem kontenerów Linux i Docker Compose. PHP ani Composer na hoście nie są potrzebne.

W folderze `lab1`:

```powershell
docker compose up --build -d
docker compose ps
```

API: `http://127.0.0.1:3000/api/v1/products`. Obraz zawiera PHP 8.4, Apache i wymagane rozszerzenia; `intl` jest włączane podczas budowania. Apache udostępnia wyłącznie katalog `public`, a żądania API kieruje do `public/index.php`. Healthcheck sprawdza odpowiedź endpointu listy produktów.

**Panel w przeglądarce: `http://127.0.0.1:3000/`.** Pod tym adresem znajduje się formularz dodawania i edycji produktu oraz lista z wyszukiwaniem, usuwaniem i historią zmian. Zakładka „Zabronione frazy” pozwala dodawać i usuwać frazy, a „Historia zmian” umożliwia odczyt historii po ID, także po usunięciu produktu. Błędy walidacji są wyświetlane przy polach formularza. Usunięcie wymaga potwierdzenia w oknie panelu.

Adres `/api/v1/products` nadal zwraca JSON; odpowiedź `[]` oznacza pustą listę produktów. Panel wykonuje żądania HTTP do tych samych endpointów. Żądania można też wysyłać klientem HTTP, korzystając z `requests.http`.

Produkty, frazy i historia są przechowywane w wolumenie `products-data` w pliku `/var/www/html/data/products.sqlite`. Wolumen zachowuje dane po odtworzeniu lub przebudowaniu kontenera. Lokalny plik `lab1/data/products.sqlite` nie jest automatycznie importowany do wolumenu.

Logi i zatrzymanie:

```powershell
docker compose logs -f api
docker compose down
```

`docker compose down` zachowuje dane. Opcja `--volumes` usuwa również wolumen z bazą, więc używaj jej wyłącznie wtedy, gdy chcesz wyczyścić dane.

Testy w tym samym obrazie, z izolowanymi tymczasowymi bazami:

```powershell
docker compose run --rm --build --no-deps --user www-data api php tests/run.php
```

Polecenie testów nie publikuje portu 3000 i nie modyfikuje bazy aplikacji. Do zmiany portu na hoście:

```powershell
$env:PORT = '3001'
docker compose up --build -d
```

Konfiguracja: `Dockerfile`, `compose.yaml`, `.dockerignore` oraz `docker/apache.conf`. Obraz oparto na [oficjalnym obrazie PHP](https://hub.docker.com/_/php), a usługę i wolumen opisano zgodnie z [Docker Compose](https://docs.docker.com/reference/compose-file/services/).

## Przykładowe dane do prezentacji

Po uruchomieniu kontenera załaduj dane demonstracyjne:

```powershell
docker compose exec -T --user www-data api php scripts/seed.php
```

Skrypt dodaje 9 produktów (po 3 z kategorii Elektronika, Książki i Odzież) oraz frazy `podrobka` i `zakaz`. Produkt `CzystyKod` ma stan 0, a nowo dodany `Laptop123` otrzymuje dwie zmiany: obniżenie ceny i zmniejszenie ilości. W panelu można więc od razu pokazać historię oraz blokadę nazwy np. `Zakaz123`.

Ponowne uruchomienie pomija istniejące nazwy i frazy, nie dopisuje ich duplikatów ani dodatkowych zmian historii i nie nadpisuje edytowanych produktów. Brakujące przykłady zostają dodane. Skrypt używa tych samych serwisów i walidatorów co API, a zapis całego zestawu odbywa się w jednej transakcji. Jeśli aktualne własne frazy blokują nazwy przykładów, skrypt zgłasza błąd i wycofuje cały zestaw.

Bez Dockera: `php -d extension=intl scripts/seed.php`. Dane nie są dodawane automatycznie przy każdym starcie aplikacji.

## Uruchomienie bez Dockera

Wymagany **PHP 8.2+ w wersji 64-bit**, SQLite 3.37+ i rozszerzenia `pdo_sqlite`, `mbstring`, `intl`; do testów również `curl`. Nie trzeba instalować pakietów Composera, uruchamiać Dockera ani osobnego serwera bazy danych. Aplikacja używa SQLite przez PDO i własnego autoloadera.

W PowerShell:

```powershell
cd C:\Users\Bartek\Desktop\studia\ZTP\lab1
php -d extension=intl -S 127.0.0.1:3000 -t public public/index.php
```

Adres API: `http://127.0.0.1:3000/api/v1/products`. Zatrzymanie: `Ctrl+C`.

Przy pierwszym uruchomieniu aplikacja automatycznie utworzy katalog `data`, bazę `data/products.sqlite`, tabele, indeks i wyzwalacze historii. Kolejne uruchomienia zachowują dane. Pliki bazy są pomijane przez Git.

Opcjonalna konfiguracja:

```powershell
$env:DB_PATH = 'data/custom.sqlite'
php -d extension=intl -S 127.0.0.1:3001 -t public public/index.php
```

`DB_PATH` może być ścieżką bezwzględną albo ścieżką względem bieżącego katalogu. Bez tej zmiennej baza zawsze znajduje się w `lab1/data/products.sqlite`, niezależnie od katalogu uruchomienia. Serwer nasłuchuje lokalnie na `127.0.0.1`.

Parametr `-d extension=intl` włącza normalizację Unicode bez modyfikowania systemowego `php.ini`. Jeśli `intl` jest już włączone w Twoim PHP, pomiń ten parametr, aby uniknąć ostrzeżenia o ponownym ładowaniu modułu. Sprawdź rozszerzenia poleceniem `php -m`; brakujące włącz w pliku wskazanym przez `php --ini`. Użyte w tym środowisku PHP 8.5.1 ma SQLite 3.49.2, `pdo_sqlite`, `mbstring` i `curl`; `intl` jest dostępne i wymaga powyższego parametru.

Dokumentacja: [serwer deweloperski PHP](https://www.php.net/manual/en/features.commandline.webserver.php), [PDO SQLite](https://www.php.net/manual/en/ref.pdo-sqlite.php). Do lokalnego laboratorium używamy serwera wbudowanego, z katalogiem publicznym `public`.

## Testy integracyjne

```powershell
php -d extension=intl tests/run.php
```

Po włączeniu wymaganych rozszerzeń w `php.ini` można też użyć opcjonalnych poleceń `composer start` i `composer test`. Plik `composer.json` dokumentuje wymagania środowiska; instalacja bibliotek ani `vendor` nie są potrzebne.

Testy są napisane w PHP. Uruchamiają rzeczywisty proces serwera PHP na losowym wolnym porcie i wykonują żądania HTTP za pomocą cURL. Każdy test ma własną plikową bazę SQLite w katalogu tymczasowym; po teście serwer jest zamykany, a jego pliki są usuwane. Testy nie korzystają z produkcyjnego `data/products.sqlite` i nie zastępują bazy ani serwisów atrapami. Test trwałości zatrzymuje i uruchamia ponownie cały proces serwera. Błąd dowolnego testu daje niezerowy kod zakończenia.

Zestaw zawiera **67 testów**, w tym:

- pełny cykl dodania, listowania, odczytu, edycji i usunięcia produktu;
- walidację nazwy, kategorii, ceny i ilości osobno dla POST i PUT;
- dolne i górne granice cen każdej kategorii oraz wartości o grosz poza zakresem;
- odrzucanie ułamków grosza, również bardzo bliskich poprawnej kwocie, bez cichego zaokrąglania;
- unikalność nazw, różne wielkości liter i równoważne zapisy Unicode;
- obowiązkowe pola, nieznane pola i nieprawidłowe typy danych;
- dodawanie i usuwanie fraz oraz natychmiastowe blokowanie tworzenia i edycji produktów;
- kompletne stany przed zmianą i po niej oraz historię zachowaną po usunięciu produktu;
- osobne wpisy historii po zmianie każdego pojedynczego pola produktu;
- trwałość produktów, fraz i historii po ponownym otwarciu aplikacji;
- kontrolowane błędy HTTP, niepoprawny JSON i równoczesne próby utworzenia duplikatu.

## Endpointy

| Metoda | Ścieżka | Wynik |
|---|---|---|
| GET | `/api/v1/products` | 200, lista produktów |
| GET | `/api/v1/products/{id}` | 200, szczegóły produktu |
| POST | `/api/v1/products` | 201, utworzony produkt i nagłówek Location |
| PUT | `/api/v1/products/{id}` | 200, zaktualizowany produkt |
| DELETE | `/api/v1/products/{id}` | 204, bez treści |
| GET | `/api/v1/products/{id}/history` | 200, chronologiczna historia, również po usunięciu produktu |
| GET | `/api/v1/forbidden-phrases` | 200, lista zabronionych fraz |
| GET | `/api/v1/forbidden-phrases/{id}` | 200, szczegóły frazy |
| POST | `/api/v1/forbidden-phrases` | 201, nowa fraza i nagłówek Location |
| DELETE | `/api/v1/forbidden-phrases/{id}` | 204, usunięcie frazy |

POST i PUT wymagają `Content-Type: application/json`. PUT zastępuje wszystkie edytowalne pola produktu. Identyfikatory muszą być dodatnimi bezpiecznymi liczbami całkowitymi.

Przykładowe ciało POST/PUT produktu:

```json
{
  "name": "Laptop123",
  "category": "Elektronika",
  "price": 2499.99,
  "quantity": 4
}
```

Przykładowe ciało POST frazy:

```json
{ "phrase": "laptop" }
```

Przykłady gotowe do wysłania z klienta obsługującego pliki `.http` znajdują się w [requests.http](requests.http). Można też użyć PowerShell:

```powershell
$product = @{
    name = 'Laptop123'
    category = 'Elektronika'
    price = 2499.99
    quantity = 4
} | ConvertTo-Json

Invoke-RestMethod -Method Post `
    -Uri 'http://127.0.0.1:3000/api/v1/products' `
    -ContentType 'application/json; charset=utf-8' `
    -Body ([System.Text.Encoding]::UTF8.GetBytes($product))

Invoke-RestMethod 'http://127.0.0.1:3000/api/v1/products'
```

## Reguły domenowe i przyjęte założenia

- Nazwa ma 3–20 znaków, wyłącznie litery Unicode (w tym polskie) i cyfry dziesiętne. Spacje, myślniki i inne znaki specjalne są odrzucane. Nazwa nie jest automatycznie przycinana.
- Nazwy są unikalne bez rozróżniania wielkości liter, po normalizacji Unicode NFC. Unikalność dodatkowo zabezpiecza ograniczenie UNIQUE w bazie.
- Obsługiwane są dokładnie trzy kategorie z PDF. Granice cen są włączne:

  | Kategoria | Minimum PLN | Maksimum PLN |
  |---|---:|---:|
  | Elektronika | 50 | 50 000 |
  | Książki | 5 | 500 |
  | Odzież | 10 | 5 000 |

- Cena jest liczbą JSON w PLN z dokładnością do groszy. W bazie przechowujemy całkowitą liczbę groszy, aby nie kumulować błędów obliczeń zmiennoprzecinkowych.
- Ilość jest nieujemną liczbą całkowitą (maksimum 9 007 199 254 740 991, aby klienci JSON również mogli odczytywać ją bez utraty precyzji). Wartości JSON takie jak `1.0` oznaczają całkowitą ilość i są akceptowane; `1.5` jest odrzucane.
- Wszystkie cztery pola produktu są wymagane zarówno przy tworzeniu, jak i edycji. Nieznane pola są odrzucane.
- Fraza ma 1–100 znaków po obcięciu skrajnych spacji. Frazy są unikalne bez rozróżniania wielkości liter i są przechowywane w bazie.
- Dopasowanie frazy działa wewnątrz całej nazwy, bez rozróżniania wielkości liter i po normalizacji NFC. Fraza `laptop` blokuje również `SuperLAPTOP123`.
- Dodanie frazy nie usuwa istniejących produktów. Ich odczyt i usunięcie nadal są możliwe, ale edycja wymaga nazwy zgodnej z aktualną listą. Usunięcie frazy natychmiast znosi blokadę.
- Historia obejmuje CREATE, UPDATE i DELETE, czas UTC oraz kompletne stany przed operacją i po niej. PUT bez faktycznej zmiany wartości nie dopisuje zdarzenia.
- Historia pozostaje dostępna po usunięciu produktu. ID usuniętych produktów nie są używane ponownie. Historia nieznanego ID zwraca 404.

## Obsługa błędów

Przykładowa odpowiedź:

```json
{
  "message": "Nieprawidłowe dane produktu.",
  "errors": {
    "quantity": "Ilość musi być nieujemną, bezpieczną liczbą całkowitą."
  }
}
```

| Status | Znaczenie |
|---|---|
| 400 | Niepoprawne dane, zabroniona fraza w nazwie, błędny JSON lub ID |
| 404 | Brak zasobu lub endpointu |
| 405 | Metoda nieobsługiwana dla istniejącej ścieżki; odpowiedź zawiera Allow |
| 409 | Nazwa produktu lub fraza już istnieje |
| 413 | Żądanie przekracza 64 KiB |
| 415 | Typ treści inny niż application/json |
| 500 | Nieoczekiwany błąd; szczegóły trafiają do logu serwera |

## Architektura N-Layer

```text
src/
  http/          kontrolery PHP, router, JSON, statusy i odpowiedzi HTTP
  services/      operacje biznesowe, unikalność i blokowanie fraz
  domain/        walidatory, DTO, interfejsy repozytoriów i błędy
  data/          repozytoria PDO SQLite, schema.sql i wyzwalacze historii
  Application.php połączenie warstw i konfiguracja aplikacji
  bootstrap.php   autoloader i sprawdzenie rozszerzeń
public/
  index.php       punkt wejścia HTTP i centralna obsługa wyjątków
  dashboard.html  widok panelu w przeglądarce
  assets/         style CSS i JavaScript komunikujący się z API
tests/
  ApiClient.php   proces serwera, klient HTTP cURL i izolacja bazy
  run.php         scenariusze i runner testów integracyjnych w PHP
```

Kontrolery nie wykonują SQL. Serwisy zależą od interfejsów `ProductStore` i `PhraseStore`, a implementacje repozytoriów otrzymują w konstruktorze. Reguły walidacji są wspólne dla tworzenia i edycji. Zapytania SQL używają parametrów PDO; unikalność dodatkowo chroni baza. Historię tworzą wyzwalacze w warstwie danych: zapis produktu i wpisu historii stanowią jedną atomową operację SQLite. Odrzucona walidacja nie zmienia produktu ani jego historii. Wszystkie pliki PHP używają `strict_types=1`.

## Powiązanie z wymaganiami PDF

| Wymaganie | Realizacja |
|---|---|
| CRUD i baza danych, 3,0 | Kontrolery produktów, ProductService, ProductRepository, SQLite |
| Dynamiczna lista fraz, +0,5 | Endpointy forbidden-phrases, PhraseService i tabela forbidden_phrases |
| N-Layer, +0,5 | Oddzielne katalogi http, services, domain i data |
| Historia w warstwie danych, +0,5 | Tabela product_history, wyzwalacze SQL i endpoint historii |
| Testy integracyjne, +0,5 | 67 testów rzeczywistych żądań HTTP, obejmujących wymagania domenowe |
| Obsługa błędów i konwencje HTTP | Błędy z nazwami pól, statusy, Location, Allow i ścieżki /api/v1 |

BDD jest omówione w części teoretycznej PDF; w tabeli ocen wymagane są testy integracyjne. Testy mają prosty runner PHP, a scenariusze zachowania opisują w nazwach i asercjach. Osobny framework Gherkin nie jest potrzebny do uruchomienia rozwiązania.
