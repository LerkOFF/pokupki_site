# Покупки

Общий список продуктов на дом: https://lerk.tech/pokupki/

Одна страница `artifacts/shopping-list.html` и несколько PHP-файлов рядом. Данные и фото лежат на сервере, в Git их нет.

| Путь | Что это |
| --- | --- |
| `artifacts/shopping-list.html` | страница: список, фото и галерея, архив, вход, тема, режим магазина |
| `artifacts/shopping-api.php` | API списка и вход (кука на 30 дней), SQLite `/var/lib/pokupki/shopping.sqlite` |
| `artifacts/shopping-photo.php` | загрузка, показ, смена главного и удаление фото, файлы в `/var/lib/pokupki/photos` |
| `artifacts/shopping-lib.php` | общий код: база, сессия, миграция цен |
| `deploy/nginx-pokupki.conf` | куски nginx для `lerk.tech`, подключаются прямо из checkout |
| `scripts/dev-router.php` | локальный запуск через `php -S` |
| `docs/pokupki.md` | как устроено, выкладка, пароль, миграция |

Хост: сервер metrika-rebenka, checkout `/var/www/pokupki`, обновление через `git pull --ff-only origin main`. Подробности и команды: [docs/pokupki.md](docs/pokupki.md).

Проект вырезан из `LerkOFF/romaBot` (ветка `claude/shopping-list-artifact-c5iuxa`). Книжный Telegram-бот из того репозитория сюда не входит и для списка не нужен.
