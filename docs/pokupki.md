# Покупки

Адрес: https://lerk.tech/pokupki/. Страница artifacts/shopping-list.html, API artifacts/shopping-api.php, фото artifacts/shopping-photo.php, общий код artifacts/shopping-lib.php.
Один общий список, вход по паролю. Кто вошёл, тот может редактировать покупки.
SQLite /var/lib/pokupki/shopping.sqlite и фото /var/lib/pokupki/photos хранятся вне Git и доступны www-data.
Клиенты перечитывают список каждые 2 секунды и при возврате на вкладку.
При параллельном редактировании одного поля сохраняется последняя запись; разные поля обновляются отдельно.
Тема и режим просмотра сохраняются в браузере. Claude API и Telegram-бот для этой страницы не нужны.

## Как это работает

- Вход: форма пароля на странице, потом подписанная кука `pokupki_s` на 30 дней (HttpOnly, Secure, SameSite=Lax, путь /pokupki). Пользоваться каждый день можно без повторного входа: сессия продлевается, когда осталась меньше половины.
- Пароль хранится на сервере только хешем в /var/lib/pokupki/auth.json (`hash` и `secret`), в Git его нет и быть не должно. Подпись куки зависит от хеша: смена пароля выкидывает всех.
- Без файла auth.json в списке не пускают никого. После 10 неверных паролей с одного адреса вход блокируется на 15 минут.
- Цена в продукте указывается за одну единицу (1 шт., 1 кг, 1 л, 1 г — как в поле «Ед.»), сумма = цена × количество. Копейки: до двух знаков, ввод «89,90» или «89.90».
- Фото: у продукта до 12 штук, первое главное и показывается в списке. Клик по миниатюре открывает галерею. Добавлять, делать главным и удалять фото можно только в режиме «Изменить», в листе продукта. Страница сжимает снимок до 1600 px перед отправкой, сервер перекодирует его в JPEG (без EXIF) и делает превью 240 px.
- Архив: «В архив» в листе продукта убирает продукт из списка и подсчётов, но хранит его со всеми фото. Ссылка «Архив» внизу списка: вернуть продукт или удалить насовсем (вместе с файлами фото).

## Выкладка

Репозиторий git@github.com:LerkOFF/pokupki_site.git, ветка main.
SSH metrika-rebenka, checkout /var/www/pokupki, origin указывает на этот репозиторий.
Обновление:

```sh
ssh metrika-rebenka 'git -C /var/www/pokupki pull --ff-only origin main'
```

Изменения nginx вступают в силу только после `nginx -t` и reload: `git pull` их сам не применяет.
Если менялся deploy/nginx-pokupki.conf:

```sh
ssh metrika-rebenka 'nginx -t && systemctl reload nginx'
```

## Пароль

Задать или сменить пароль (хеш считается на сервере, пароль читается со стандартного ввода и в списке процессов не светится):

```sh
ssh -t metrika-rebenka 'read -rs -p "Новый пароль: " P && echo && P="$P" php -r "echo json_encode([\"hash\" => password_hash(getenv(\"P\"), PASSWORD_BCRYPT), \"secret\" => bin2hex(random_bytes(32))]);" > /var/lib/pokupki/auth.json && chown www-data:www-data /var/lib/pokupki/auth.json && chmod 640 /var/lib/pokupki/auth.json'
```

Проверка: `curl -s -o /dev/null -w '%{http_code}' https://lerk.tech/pokupki/api` отвечает 401, а после входа на странице список открывается.
Пароль вводится как есть, кириллица допустима (страница приводит его к форме NFC).

## Миграция цен

До 01.10.2026 цена хранилась за всё количество целиком, теперь за единицу. При первом обращении к API после обновления `pokupki_migrate_unit_price` один раз пересчитывает цены (цена ÷ количество, до копеек), перед этим копируя базу в /var/lib/pokupki/backup-before-unit-price.sqlite. Отметка о выполнении лежит в таблице `meta`.

## Первый запуск

Создать /var/lib/pokupki с владельцем www-data, создать пароль (см. выше), включить deploy/nginx-pokupki.conf
в HTTPS server lerk.tech в /etc/nginx/sites-available/site-lerk.tech, выполнить nginx -t и reload.
443 обслуживает Xray; его конфигурация для этой страницы не меняется.
DNS: существующие A @ и www = 157.22.231.158; для пути /pokupki DNS не нужен.
Резервировать SQLite и каталог photos отдельно от Git. Не редактировать tracked-файлы на сервере.

## Локальный запуск

```sh
mkdir -p /tmp/pokupki-data
php -r 'file_put_contents("/tmp/pokupki-data/auth.json", json_encode(["hash" => password_hash("test", PASSWORD_BCRYPT), "secret" => bin2hex(random_bytes(32))]));'
POKUPKI_DATA=/tmp/pokupki-data php -S 127.0.0.1:8080 scripts/dev-router.php
```

Открыть http://127.0.0.1:8080/pokupki/, пароль из команды выше. Нужен PHP с расширениями pdo_sqlite и gd.
