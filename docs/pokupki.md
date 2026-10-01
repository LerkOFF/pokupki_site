# Покупки

Адрес: https://lerk.tech/pokupki/. Страница artifacts/shopping-list.html, API artifacts/shopping-api.php.
Один общий список без регистрации: любой посетитель адреса может редактировать покупки.
SQLite /var/lib/pokupki/shopping.sqlite хранится вне Git и доступна www-data.
Клиенты перечитывают список каждые 2 секунды и при возврате на вкладку.
При параллельном редактировании одного поля сохраняется последняя запись; разные поля обновляются отдельно.
Тема и режим просмотра сохраняются в браузере. Claude API и Telegram-бот для этой страницы не нужны.

## Выкладка

Репозиторий git@github.com:LerkOFF/pokupki_site.git, ветка main.
SSH metrika-rebenka, checkout /var/www/pokupki, origin указывает на этот репозиторий.
Обновление:

```sh
ssh metrika-rebenka 'git -C /var/www/pokupki pull --ff-only origin main'
```

Изменения nginx вступают в силу только после `nginx -t` и reload: `git pull` их сам не применяет.

Для первого запуска создать /var/lib/pokupki с владельцем www-data, включить deploy/nginx-pokupki.conf
в HTTPS server lerk.tech в /etc/nginx/sites-available/site-lerk.tech, выполнить nginx -t и reload.
443 обслуживает Xray; его конфигурация для этой страницы не меняется.
DNS: существующие A @ и www = 157.22.231.158; для пути /pokupki DNS не нужен.
Резервировать SQLite отдельно от Git. Не редактировать tracked-файлы на сервере.
