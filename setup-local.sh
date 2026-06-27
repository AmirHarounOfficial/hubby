#!/bin/bash

echo "🏗️ Setting up HubbyGlobal Local Environment..."

# 1. Prepare Backend Env
if [ ! -f backend/.env ]; then
    echo "📄 Creating backend .env..."
    cp backend/.env.example backend/.env
    # Set default local DB/Redis values in .env
    sed -i 's/DB_HOST=127.0.0.1/DB_HOST=mysql/g' backend/.env
    sed -i 's/DB_USERNAME=root/DB_USERNAME=sail/g' backend/.env
    sed -i 's/DB_PASSWORD=/DB_PASSWORD=password/g' backend/.env
    sed -i 's/REDIS_HOST=127.0.0.1/REDIS_HOST=redis/g' backend/.env
fi

# 2. Start Docker
echo "🐳 Starting Docker containers..."
cd docker && docker-compose up -d --build && cd ..

# 3. Backend Setup
echo "php artisan key:generate..."
docker exec -it hubby_global_app php artisan key:generate
echo "php artisan migrate:fresh --seed..."
docker exec -it hubby_global_app php artisan migrate:fresh --seed

echo "✨ Local setup complete!"
echo "Dashboard: http://localhost:3000"
echo "API: http://localhost:8000"
