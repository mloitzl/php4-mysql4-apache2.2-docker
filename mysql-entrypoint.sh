#!/bin/sh
set -e

echo "Starting MySQL in the background..."
mysqld_safe &

# Wait for MySQL to be ready
echo "Waiting for MySQL to start..."
while ! mysqladmin ping -h "localhost" --silent; do
    sleep 1
done
echo "MySQL started!"

# Check if user already exists
USER_EXISTS=$(mysql -u root -sNe "SELECT COUNT(*) FROM mysql.user WHERE User='${MYSQL_INIT_USER}' AND Host='${MYSQL_INIT_HOST}';")

if [ "$USER_EXISTS" -eq 0 ]; then
    echo "Creating user '${MYSQL_INIT_USER}'..."
    mysql -u root <<EOF
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_INIT_USER}'@'${MYSQL_INIT_HOST}' IDENTIFIED BY '${MYSQL_INIT_PASSWORD}' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
    echo "User '${MYSQL_INIT_USER}' created successfully!"
else
    echo "User '${MYSQL_INIT_USER}' already exists, skipping user creation."
fi

# Keep the container running
wait
