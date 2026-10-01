# Покупки

Общий список продуктов на дом: https://lerk.tech/pokupki/

Одна страница `artifacts/shopping-list.html` и один PHP-файл `artifacts/shopping-api.php`. Данные в SQLite на сервере, в Git их нет.

| Путь | Что это |
| --- | --- |
| `artifacts/shopping-list.html` | страница списка, тема, режим магазина, опрос API каждые 2 секунды |
| `artifacts/shopping-api.php` | API, SQLite `/var/lib/pokupki/shopping.sqlite` |
| `deploy/nginx-pokupki.conf` | куски nginx для `lerk.tech`, подключаются прямо из checkout |
| `docs/pokupki.md` | выкладка, первый запуск |

Хост: сервер metrika-rebenka, checkout `/var/www/pokupki`, обновление через `git pull --ff-only origin main`. Подробности и команды: [docs/pokupki.md](docs/pokupki.md).

Проект вырезан из `LerkOFF/romaBot` (ветка `claude/shopping-list-artifact-c5iuxa`). Книжный Telegram-бот из того репозитория сюда не входит и для списка не нужен.
