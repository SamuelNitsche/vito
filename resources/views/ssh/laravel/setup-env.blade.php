cd {{ $path }}

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
    echo "Copied .env.example to .env"
fi

if [ -f .env ]; then
    php artisan key:generate --force
    echo "Application key generated"
else
    echo "No .env file found"
fi
