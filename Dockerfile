FROM php:8.2-apache

# Install system dependencies and Python
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    supervisor \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Setup Python Virtual Environment and install packages
RUN python3 -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"
RUN pip install --no-cache-dir mysql-connector-python xgboost ortools joblib pandas scikit-learn
ENV PYTHONUNBUFFERED=1
RUN printf "upload_max_filesize=8M\npost_max_size=8M\nmemory_limit=512M\nmax_execution_time=300\nmax_input_time=300\n" > /usr/local/etc/php/conf.d/fairmedalloc-upload.ini

# Copy the entire codebase to Apache's folder
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Copy the supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set environment variable so the PHP worker knows exactly where Python is
ENV PYTHON_BIN="/opt/venv/bin/python"
ENV FAIRMED_PYTHON_BIN="/opt/venv/bin/python"

# Expose standard web port
EXPOSE 80

# Start Supervisor (which starts both Apache and your Worker)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
