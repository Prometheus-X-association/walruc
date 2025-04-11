# Technical Guide: Developing Matomo Plugins with External API Integration

This guide provides comprehensive instructions for setting up a Matomo development environment.

## Development Environment Setup

### Test Page Preparation

First, create a simple web page to test your Matomo tracking:

1. Create a minimal `index.html` file in a directory of your choice
2. Start a simple HTTP server in that directory:
   ```bash
   python -m http.server 3000
   ```
3. Access the page at `http://localhost:3000`

### Matomo Installation Options

There are two main approaches to install Matomo:

1. **Local installation**: Clone the repository, use Composer for dependencies, and set up HTTP server and MySQL
2. **Docker-based installation**: Use pre-configured Docker images (simpler, but limited for plugin development)
   like [this official example here](https://github.com/matomo-org/docker/tree/master/.examples).

> **Note**: The official Matomo Docker images are designed for production use and have limitations for plugin
> development.

### Hybrid Approach with Docker

For plugin development, a hybrid approach works best:

1. Use Docker for the infrastructure (PHP, MariaDB, Nginx).
2. Mount a local Matomo installation as a volume

#### Required Files

- [docker-compose.yml](./development/docker-compose.yml) : Inspired by the official file.

- [Dockerfile](./development/Dockerfile): Based on the official Matomo PHP-FPM Dockerfile, but without the Matomo code
  retrieval part

- [db.env](./development/db.env): Database configuration file

- [nginx.conf](./development/nginx.conf): Nginx configuration for Matomo (similar to the example from
  matomo-org/docker repository)

- [db_init.sql](./development/db_init.sql): For test database initialization (will be covered in the Testing section)

### Setup Steps

1. Start the Docker services:
   ```bash
   docker-compose up -d
   ```

2. Install Matomo dependencies:
   ```bash
   docker-compose exec app composer install
   ```

3. Access the Matomo installation wizard:
   ```
   http://localhost:8080/
   ```

4. Follow the installation wizard:
    - System check (should pass with the provided Docker setup)
    - Database configuration (use the service name `db` and credentials from `db.env`)
    - Create the main admin user
    - Configure your test website (`http://localhost:3000`)
    - Copy the provided JavaScript tracking code to your test page

5. Enable development mode:
   ```bash
   docker-compose exec app ./console development:enable
   ```

## Understanding Matomo Plugin Architecture

Matomo plugins follow a specific structure and can interact with the system through various mechanisms:

### Plugin Structure

- **Main plugin class**: Extends `\Piwik\Plugin` and defines the plugin's behavior
- **plugin.json**: Contains metadata (name, description, version, etc.)
- **Documentation files**: README.md, CHANGELOG.md, screenshots/ directory
- **API endpoints**: Implemented in an API.php file if needed
- **Settings**: User and system settings classes

## Creating a Plugin with External API Integration

### Generating a Plugin Skeleton

Use Matomo's CLI tool to create the basic plugin structure:

```bash
docker-compose exec app ./console generate:plugin --name="YourPlugin"
```

This creates:

- YourPlugin.php (Main plugin class)
- plugin.json (Metadata)
- README.md, CHANGELOG.md (Documentation files)

### Adding Tracker Functionality

The documentation is [here](https://developer.matomo.org/guides/tracking-requests).

To handle tracking events, you need to:

1. Mark your plugin as a tracker plugin in the main class:

```php
use Piwik\Plugin;

class YourPlugin extends Plugin
{
    public function isTrackerPlugin(): bool
    {
        return true;
    }
}
```

2. Create a `Tracker/RequestProcessor.php` file that extends `\Piwik\Tracker\RequestProcessor`

## Request Processing in Matomo

Matomo's request processing cycle offers several hooks for plugins with methods that are executed in a specific order.

### Example Implementation for WALRUC

For the WALRUC plugin, we focus on the `recordLogs(VisitProperties $visitProperties, Request $request)` method to
capture visit data, send it to an external LRC, and then store the result in an LRS.

## Configuration Management

Matomo offers two approaches to plugin configuration:

### File-Based Configuration

Add your plugin's settings to Matomo's configuration files:

1. Add a section to `config/config.ini.php`:
   ```ini
   [YourPlugin]
   yourVariable = "http://example.com/api/convert"
   ```

2. Access the configuration in your code:
   ```php
   $endpoint = $this->config->getFromLocalConfig('YourPlugin')['yourVariable'];
   ```

### Admin UI Configuration

For settings accessible through the Matomo admin interface,
see [the doc here](https://developer.matomo.org/guides/plugin-settings):

1. Create a settings class

2. Access the settings in your code

## Dependency Injection

Matomo uses PHP-DI for dependency injection. For your plugin:

1. Define service bindings in `config/tracker.php` (for tracker-specific services) or `config.php` (for general
   services):

```php
return [
    HttpClientInterface::class => DI::autowire(HttpClient::class),
];
```

2. Use constructor injection in your classes:

```php
class MyService
{
    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }
}
```

## Making External HTTP Requests

Matomo provides a built-in HTTP client for making external requests:

For improved reliability, implement a retry mechanism.

## Setting Up Tests

### Preparing the Testing Environment

1. Create a database initialization script (`db_init.sql`) [like this](./development/db_init.sql).

2. Configure Matomo for testing by adding to `config/config.ini.php`:
   ```ini
   [tests]
   http_host = "web"
   request_uri = "/"
   remote_addr = "172.17.0.1"
   port = 80

   [database_tests]
   host = "db"
   username = "<see your db.env file>"
   password = "<see your db.env file>"
   ```

3. Update Nginx configuration for test proxying (not needed if you use this
   file [development/nginx.conf](./development/nginx.conf))

4. Initialize Git submodules:
   ```bash
   git submodule init 
   git submodule update
   ```

5. Set up the test fixture:
   ```bash
   docker-compose exec app ./console tests:setup-fixture OmniFixture
   ```

### Creating Unit Tests

Place your test files in the `tests/Unit` directory:

### Running Tests

Run your plugin's tests with:

```bash
docker-compose exec app ./console tests:run YourPlugin
```

## Best Practices

View logs with:

```bash
docker-compose exec app ./console log:watch
```
