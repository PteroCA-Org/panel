#!/bin/bash

echo "🐳 Initializing Docker environment..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Error: Docker is not installed!"
    echo ""
    echo "Please install Docker first:"
    echo "  - Ubuntu/Debian: https://docs.docker.com/engine/install/ubuntu/"
    echo "  - CentOS/RHEL: https://docs.docker.com/engine/install/centos/"
    echo "  - Windows: https://docs.docker.com/desktop/install/windows-install/"
    echo "  - macOS: https://docs.docker.com/desktop/install/mac-install/"
    echo ""
    exit 1
fi

# Check if Docker Compose is installed
if ! docker compose version &> /dev/null; then
    echo "❌ Error: Docker Compose is not installed!"
    echo ""
    echo "Please install Docker Compose first:"
    echo "  - Linux: https://docs.docker.com/compose/install/"
    echo "  - Windows/macOS: Usually included with Docker Desktop"
    echo ""
    exit 1
fi

# Check if Docker daemon is running
if ! docker info &> /dev/null; then
    echo "❌ Error: Docker daemon is not running!"
    echo ""
    echo "Please start Docker daemon first:"
    echo "  - Linux: sudo systemctl start docker"
    echo "  - Windows/macOS: Start Docker Desktop"
    echo ""
    exit 1
fi

echo "✅ Docker and Docker Compose are installed and running"

# Parse command line arguments
ENVIRONMENT=""
for arg in "$@"; do
    case $arg in
        --env=*)
            ENVIRONMENT="${arg#*=}"
            shift
            ;;
        dev|prod)
            ENVIRONMENT="$arg"
            shift
            ;;
        *)
            # Unknown argument
            ;;
    esac
done

# If no environment specified, ask user
if [ -z "$ENVIRONMENT" ]; then
    echo "Please select environment:"
    echo "1) Development (dev)"
    echo "2) Production (prod)"
    read -p "Enter choice (1-2): " choice
    case $choice in
        1) ENVIRONMENT="dev" ;;
        2) ENVIRONMENT="prod" ;;
        *) echo "Invalid choice. Using development as default."; ENVIRONMENT="dev" ;;
    esac
fi

# Validate environment
if [ "$ENVIRONMENT" != "dev" ] && [ "$ENVIRONMENT" != "prod" ]; then
    echo "Invalid environment: $ENVIRONMENT. Using development as default."
    ENVIRONMENT="dev"
fi

# Set compose file based on environment
if [ "$ENVIRONMENT" = "dev" ]; then
    COMPOSE_FILE="docker-compose.yml"
    WEB_PORT="8000"
    DB_VOLUME_NAME="panel_db_data_dev"
    echo "🔧 Setting up DEVELOPMENT environment..."
else
    COMPOSE_FILE="docker-compose.prod.yml"
    WEB_PORT="80"
    DB_VOLUME_NAME="panel_db_data_prod"
    echo "🔧 Setting up PRODUCTION environment..."
fi

# Check if database volume already exists (for both dev and prod)
DB_VOLUME_EXISTS=$(docker volume ls -q | grep "$DB_VOLUME_NAME" || echo "")

if [ ! -z "$DB_VOLUME_EXISTS" ]; then
    echo "📄 Existing database volume detected: $DB_VOLUME_NAME"
    
    # Database exists - check if .env exists and has DATABASE_URL
    if [ -f ".env" ] && grep -q "DATABASE_URL=" .env; then
        # Extract password from existing DATABASE_URL
        DB_PASSWORD=$(grep "DATABASE_URL=" .env | cut -d':' -f3 | cut -d'@' -f1 | tr -d '"')
        if [ "$ENVIRONMENT" = "dev" ]; then
            DB_ROOT_PASSWORD="root"
        else
            DB_ROOT_PASSWORD="existing" # Not used by application when DB exists
        fi
        echo "✅ Using existing database with credentials from .env"
    else
        echo "❌ ERROR: Database volume exists but no .env file with DATABASE_URL found!"
        echo ""
        echo "📋 You need to create .env file with DATABASE_URL pointing to existing database:"
        echo "   DATABASE_URL=\"mysql://user:YOUR_PASSWORD@db:3306/pteroca?serverVersion=8.0\""
        echo ""
        echo "💡 If you don't know the password, you can:"
        echo "   1) Remove the database volume: docker volume rm $DB_VOLUME_NAME"
        echo "   2) Run this script again to create fresh database"
        echo ""
        exit 1
    fi
else
    echo "🆕 No existing database found - creating new one"
    if [ "$ENVIRONMENT" = "dev" ]; then
        DB_PASSWORD="password"
        DB_ROOT_PASSWORD="root"
        echo "🔧 Using development database credentials"
    else
        # Generate secure passwords for new production database
        DB_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-25)
        DB_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-25)
        echo "🔐 Generated secure database passwords for new production installation"
    fi
fi

# Handle .env file only if database is new or .env doesn't exist
if [ -z "$DB_VOLUME_EXISTS" ] || [ ! -f ".env" ]; then
    # Check if .env file exists and handle appropriately
    if [ -f ".env" ]; then
        echo "⚠️  Configuration file .env already exists!"
        echo ""
        echo "❓ How would you like to proceed?"
        echo "1) Backup current .env (rename to .env_bak) and create new one"
        echo "2) Remove current .env and create new one"
        echo "3) Cancel installation (keep existing .env)"
        echo ""
        read -p "Enter your choice (1-3): " env_choice
        
        case $env_choice in
            1)
                if [ -f ".env_bak" ]; then
                    echo "⚠️  Warning: .env_bak already exists, it will be overwritten"
                    read -p "Continue? (y/N): " confirm
                    if [[ ! $confirm =~ ^[Yy]$ ]]; then
                        echo "❌ Installation cancelled"
                        exit 1
                    fi
                fi
                mv .env .env_bak
                cp .env.SAMPLE .env
                echo "✅ Current .env backed up to .env_bak, new .env created"
                ;;
            2)
                read -p "⚠️  Are you sure you want to delete current .env? (y/N): " confirm
                if [[ ! $confirm =~ ^[Yy]$ ]]; then
                    echo "❌ Installation cancelled"
                    exit 1
                fi
                rm .env
                cp .env.SAMPLE .env
                echo "✅ Current .env removed, new .env created"
                ;;
            3)
                echo "ℹ️  Installation cancelled - keeping existing .env"
                echo "   You can manually run: docker compose -f $COMPOSE_FILE up -d"
                exit 0
                ;;
            *)
                echo "❌ Invalid choice. Installation cancelled"
                exit 1
                ;;
        esac
    else
        echo "📄 Creating .env from .env.SAMPLE..."
        cp .env.SAMPLE .env
    fi
else
    echo "✅ Using existing .env configuration with existing database"
fi

# Update .env with generated passwords for production
if [ "$ENVIRONMENT" = "prod" ] && [ -z "$DB_VOLUME_EXISTS" ]; then
    echo "🔧 Configuring .env with generated production passwords..."
    # Update DATABASE_URL with generated password
    sed -i "s/DATABASE_URL=.*/DATABASE_URL=\"mysql:\/\/user:$DB_PASSWORD@db:3306\/pteroca?serverVersion=8.0\"/" .env
    
    # Add production MySQL passwords to .env
    echo "" >> .env
    echo "# Production database passwords - auto-generated" >> .env
    echo "MYSQL_PASSWORD=$DB_PASSWORD" >> .env
    echo "MYSQL_ROOT_PASSWORD=$DB_ROOT_PASSWORD" >> .env
elif grep -q "DATABASE_URL=$" .env || grep -q "DATABASE_URL=.*password.*" .env; then
    echo "🔧 Configuring DATABASE_URL for Docker with generated password..."
    sed -i "s/DATABASE_URL=.*/DATABASE_URL=\"mysql:\/\/user:$DB_PASSWORD@db:3306\/pteroca?serverVersion=8.0\"/" .env
fi

# Set APP_ENV to match selected environment
echo "🔧 Setting APP_ENV to $ENVIRONMENT..."
sed -i "s/APP_ENV=.*/APP_ENV=$ENVIRONMENT/" .env

# Check if APP_SECRET is empty and generate one if needed
if grep -q "APP_SECRET=$" .env; then
    echo "🔐 Generating APP_SECRET..."
    # Generate a random 32-character hex string
    NEW_SECRET=$(openssl rand -hex 32)
    sed -i "s/APP_SECRET=.*/APP_SECRET=$NEW_SECRET/" .env
    echo "✅ APP_SECRET generated successfully"
fi

echo "🏗️  Building containers..."
docker compose -f $COMPOSE_FILE build

# Set environment variables for docker-compose
export MYSQL_PASSWORD=$DB_PASSWORD
export MYSQL_ROOT_PASSWORD=$DB_ROOT_PASSWORD
export DATABASE_URL="mysql://user:$DB_PASSWORD@db:3306/pteroca?serverVersion=8.0"

echo "🚀 Starting environment..."
if [ "$ENVIRONMENT" = "dev" ]; then
    docker compose -f $COMPOSE_FILE up -d db phpmyadmin
else
    docker compose -f $COMPOSE_FILE up -d db
fi

echo "⏳ Waiting for database to be ready..."
sleep 10

echo "🌐 Starting web server (with automatic migrations)..."
docker compose -f $COMPOSE_FILE up -d web

echo "✅ Environment ready!"
echo "🌐 Web application: http://localhost:$WEB_PORT"
echo "🗄️  Database: localhost:3306"
echo "   - Database: pteroca"
echo "   - User: user"
echo "   - Password: $DB_PASSWORD"
echo "🌍 Timezone: inherited from host"
echo ""
echo "📝 Usage:"
echo "   Stop: docker compose -f $COMPOSE_FILE down"
echo "   Logs: docker compose -f $COMPOSE_FILE logs -f"
echo "   Restart: docker compose -f $COMPOSE_FILE restart"
echo ""
if [ "$ENVIRONMENT" = "dev" ]; then
    echo "🧪 PHPMyAdmin: http://localhost:8080"
    echo "   - Server: db"
    echo "   - Username: user"
    echo "   - Password: $DB_PASSWORD"
else
    echo "🔒 PHPMyAdmin disabled for production security"
    echo ""
    echo "⚠️  IMPORTANT: Save these database passwords securely!"
    echo "🔐 Database User Password: $DB_PASSWORD"
    echo "🔐 Database Root Password: $DB_ROOT_PASSWORD"
fi

echo ""
echo "⏰ Cron job status:"
echo "   ✅ PteroCA cron job automatically configured"
echo "   📋 Schedule: Every minute (billing, suspensions, etc.)"
echo "   🔄 Command: php /app/bin/console pteroca:cron:schedule"
echo ""
echo "🎯 Next steps to complete installation:"
echo "   Option 1: Web wizard installer at http://localhost:$WEB_PORT/first-configuration"
echo "   Option 2: CLI command: docker compose -f $COMPOSE_FILE exec web php bin/console pteroca:system:configure"
echo ""
echo "🎉 Installation complete! Visit the web wizard to finalize setup."
