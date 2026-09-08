# Запуск
```bash
# 1. Dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Databases (adjust DB_USERNAME / DB_PASSWORD in .env first)
createdb wgt_spain
createdb wgt_spain_test

# 4. Schema
php artisan migrate --seed
```

Make sure PostgreSQL and Redis are running:
MACOS
для сервера я використовую Laravel Herd
```bash
brew services start postgresql@17
brew services start redis
redis-cli ping   # -> PONG
```
підключити базу даних в .env


```bash
# HTTP server
php artisan serve                 # http://localhost:8000

# Queue worker (required — imports are processed in a Job)
php artisan queue:work redis

# Migrations
php artisan migrate:fresh --seed  # reset + seed
```

# Початок тестування
```

```

# Варто звернути увагу
## Тести алгоритмів!!
поганий алгорит імпорту де багато запитів і неефиктивно опрацьовує великі об'єми даних
```
 private function importOfferBad(Import $import, array $payload): void
    {
        $property = Property::query()->updateOrCreate(
            ['code' => $payload['property']['code']],
            [
                'name' => $payload['property']['name'],
                'city' => $payload['property']['city'],
            ]
        );

        // Upsert on (supplier_id, external_id): an offer already seen under a different
        // import is updated and repointed, never duplicated.
        Offer::query()->updateOrCreate(
            [
                'supplier_id' => $import->supplier_id,
                'external_id' => $payload['external_id'],
            ],
            [
                'property_id' => $property->id,
                'import_id' => $import->id,
                'check_in' => $payload['check_in'],
                'check_out' => $payload['check_out'],
                'max_guests' => $payload['max_guests'],
                'price' => $payload['price'],
                'currency' => Str::upper($payload['currency']),
                'available_units' => $payload['available_units'],
                'expires_at' => $payload['expires_at'],
            ]
        );

        $import->increment('processed_offers');
    }
```
```
+------------------+------------+
|                  |            |
+------------------+------------+
| import id        | 11         |
| offers sent      | 50000      |
| path             | row by row |
| status           | completed  |
| processed_offers | 50000      |
| queries          | 205006     |
| peak memory      | 175 MB     |
| time             | 157 774 ms |
| error            | —          |
+------------------+------------+
```

Ключові проблеми які треба вирішити
- К-сть запитів в БД
- На кожен запит окрема транзакція
- $import->increment('processed_offers'); MVCC створює нову версію рядка на кожен


Рішення
1. Об'єднуємо всі операції в одну транзакцію
2. Ділимо увесь payload на чанки 500-1000 (можна вказувати)
3. Починаємо імпорт чанку
3.1. Алгоритм для чанку:
В циклі проходимося по offers
- Створити масив з property унікальними без дублікацій!
- Створити масив з offers без дублікацій! і в цьому масиві буде код property щоб далі в запиті на створення offer зразу сама posgreSQL буде шукати потрібний id property по коду.

3.2 Логіка запитів:
- будуємо прямий запит в postgreSQL як строка і вставляємо в неї потрібні дані з масиву спочатку масив з properties потім масив з offers

4. для кожного чатку оновлюємо к-сть опрацьованих


16-18 РАЗІВ ПРИСКОРЕННЯ!
```
див ф-я createOffersFromPayload()
```
```
+------------------+-----------+
|                  |           |
+------------------+-----------+
| import id        | 12        |
| offers sent      | 50000     |
| path             | bulk      |
| status           | completed |
| processed_offers | 50000     |
| queries          | 206       |
| peak memory      | 187 MB    |
| time             | 9722 ms   |
| error            | —         |
+------------------+-----------+
```

## Інше

app/Services/ - шар з бізнес-логікою. Потрібен для комплексних операцій, щоб вони були реалізовані в одному місці.
На приклад ф-я бронювання прописана тільки в цьому місці і далі її можна виконувати у різних місцях.
Controllers - будує логіку використання. На приклад, забронювати один будинок або зідйснити декілька бронювань в циклі або забронювати, знайти дочірній обєкт і оновити йому щось.
Також: Тести, Крон та інші місця посилаються тільки на ф-ї з сервісів, а не лізуть на пряму в базу даних.


app/Exceptions/
Я би створив кожен великий модуль свій клас Exception або один маштабований на всю систему.
На приклад: WGTSpainException (назва щоб було зрозуміло, що то кастомний клас, а не від Laravel) (Для помилок які можна показати користувачу), SystemException (Для системних помилок. Jobs, cache, робота з БД та інше).


# Структура БД та дані

```
users ───1:1── suppliers ──┬──< imports   (payload: offers jsonb)
 (type)                    │
                           └──< offers >── properties
                                   │
                                   └──< reservations
```

app/Models/
доступ до БД, зв'язки, касти

database/migrations/
створює схему БД і unique-ключі

database/seeders/
додає supplier-a і supplier-b

database/factories/
генерує дані для тестів

# Api

routes/api.php
маршрути
|
v
app/Http/Requests/
валідує вхідні дані
|
v
app/Http/Controllers/Api/
оркеструє. використовує готові ф-ї з бізнес-логіки прописані в Services
|
v
app/Services/
бізнес-логіка: комплексні операції
|
v
app/Jobs/
асинхронна обробка імпорту у черзі
|
v
app/Http/Resources/
формує JSON відповіді
|
v
app/Data/
DTO для передачі параметрів у сервіси
|
v
app/Enums/
статуси імпорту і типи користувачів
|
v
app/Exceptions/
один клас помилки: message + code + context
|
v
tests/Feature/
тести всіх ендпоінтів і job
