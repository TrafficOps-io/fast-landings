#!/usr/bin/env python3
"""Exercise the production Caddy route and isolated FPM image over real HTTP.

Build: docker build --target landing-php -t fast-landings-landing-php:runtime-test \
    -f deploy/Dockerfile .
Run: python3 deploy/tests/runtime-smoke.py
Requires Docker, Python 3 and the caddy:2.10.2-alpine image. All containers,
networks and fixtures created here are removed, including after a failed test.
"""

import http.client
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid


def docker(*args):
    result = subprocess.run(["docker", *args], text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr)
    return result.stdout.strip()


def main():
    name = "landing-runtime-test-" + uuid.uuid4().hex[:12]
    runtime, edge, writer = name + "-php", name + "-edge", name + "-writer"
    hosts_volume, releases_volume = name + "-hosts", name + "-releases"
    image = os.environ.get("LANDING_PHP_TEST_IMAGE", "fast-landings-landing-php:runtime-test")
    deploy = Path(__file__).resolve().parent.parent
    with tempfile.TemporaryDirectory(prefix="landing-runtime-test-") as directory:
        fixtures = Path(directory)
        # Docker Desktop needs traversal/read permission for our non-root UIDs.
        fixtures.chmod(0o755)
        hosts = fixtures / "hosts"
        releases = fixtures / "releases"
        site = releases / "first"
        second = releases / "second"
        static_site = releases / "static"
        nested = site / "nested"
        for folder in (hosts, releases, site, second, static_site, nested):
            folder.mkdir()
        (hosts / "site.test").symlink_to("../releases/first")
        (hosts / "static.test").symlink_to("../releases/static")
        (static_site / "index.html").write_text("static-fallback")
        (site / "index.html").write_text("static-fallback")
        (site / "index.php").write_text('''<?php
session_start();
$_SESSION['visits'] = ($_SESSION['visits'] ?? 0) + 1;
require 'include.inc.php';
echo '<form method="post" action="success.php"><input name="name"></form>';
''')
        (site / "include.inc.php").write_text("<?php echo 'php-index';")
        (site / "success.php").write_text('''<?php
session_start();
header('Content-Type: application/json');
header('X-Landing-Test: executed');
http_response_code(201);
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'get' => $_GET,
    'post' => $_POST, 'visits' => $_SESSION['visits'] ?? null]);
''')
        (site / "upload.php").write_text('''<?php
$file = $_FILES['attachment'];
$contents = file_get_contents($file['tmp_name']);
$moved = move_uploaded_file($file['tmp_name'], sys_get_temp_dir().'/upload.txt');
header('Content-Type: application/json');
echo json_encode(['name' => $file['name'], 'contents' => $contents,
    'moved' => $moved, 'post' => $_POST]);
''')
        (site / "isolation.php").write_text('''<?php
header('Content-Type: application/json');
echo json_encode(['other_site' => @file_get_contents('/srv/landings/releases/second/index.php'),
    'system_file' => @file_get_contents('/etc/passwd'),
    'shared_tmp' => @scandir('/tmp/sites'),
    'runtime_source' => @file_get_contents('/opt/fast-landings/runtime-bootstrap.php'),
    'can_widen' => @ini_set('open_basedir', '/'),
    'panel_secret' => getenv('APP_KEY'),
    'can_write_release' => @file_put_contents(__DIR__.'/write.txt', 'no'),
    'tmp' => sys_get_temp_dir()]);
''')
        (site / "redirect.php").write_text("<?php header('Location: /success.php?sent=1', true, 303);")
        (nested / "index.php").write_text("<?php echo 'nested-php';")
        (site / "static.html").write_text("static-page")
        (site / "style.css").write_text("body { color: red; }")
        for source in ("secret.tpl.php", "secret.tpl.html", "secret.inc", "secret.PHP", "secret.php.bak", ".env", "secret.sql", "secret.php5"):
            (site / source).write_text("never-download-this-source")
        (second / "index.php").write_text("<?php echo 'second-release';")

        # Use the exact production route body, with HTTP host replacing TLS SNI
        # solely in this local listener. TLS authorization is validated elsewhere.
        production = (deploy / "Caddyfile").read_text()
        start = production.index("\troute {", production.index("https:// {"))
        end = production.index("\n}\n\n# Container-only", start)
        route = production[start:end].replace("{http.request.tls.server_name}", "{host}")
        config = fixtures / "Caddyfile"
        config.write_text("{\n admin off\n auto_https off\n}\n:8080 {\n" + route + "\n}\n")

        docker("network", "create", name)
        try:
            # A container performs projection writes, just as in production.
            # Named volumes avoid Docker Desktop's host-side symlink caches.
            docker("volume", "create", hosts_volume)
            docker("volume", "create", releases_volume)
            docker("run", "-d", "--name", writer, "--network", "none",
                   "-v", hosts_volume + ":/srv/landings/hosts",
                   "-v", releases_volume + ":/srv/landings/releases",
                   "--entrypoint", "sleep", "caddy:2.10.2-alpine", "300")
            docker("cp", str(releases) + "/.", writer + ":/srv/landings/releases")
            docker("exec", writer, "ln", "-s", "../releases/first", "/srv/landings/hosts/site.test")
            docker("exec", writer, "ln", "-s", "../releases/static", "/srv/landings/hosts/static.test")
            docker("run", "-d", "--name", runtime, "--network", name,
                   "--network-alias", "landing-php", "--read-only", "--cap-drop=ALL",
                   "--security-opt=no-new-privileges:true", "--pids-limit=64", "--memory=640m",
                   "--tmpfs", "/tmp:rw,nosuid,nodev,noexec,size=64m,uid=10004,gid=10001,mode=0700",
                   "-v", releases_volume + ":/srv/landings/releases:ro", image)
            docker("run", "-d", "--name", edge, "--network", name,
                   "--read-only", "--cap-drop=ALL", "--cap-add=NET_BIND_SERVICE", "--security-opt=no-new-privileges:true", "--user", "10003:10001",
                   "--tmpfs", "/tmp:rw,nosuid,nodev,noexec,size=32m",
                   "--tmpfs", "/data:rw,nosuid,nodev,noexec,size=8m,uid=10003,gid=10001",
                   "--tmpfs", "/config:rw,nosuid,nodev,noexec,size=8m,uid=10003,gid=10001",
                   "-p", "127.0.0.1::8080", "-v", str(config) + ":/etc/caddy/Caddyfile:ro",
                   "-v", hosts_volume + ":/srv/landings/hosts:ro",
                   "-v", releases_volume + ":/srv/landings/releases:ro", "caddy:2.10.2-alpine")
            port = int(docker("port", edge, "8080/tcp").rsplit(":", 1)[1])

            def request(path="/", method="GET", body=None, headers=None, host="site.test"):
                connection = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
                connection.request(method, path, body=body, headers={"Host": host, **(headers or {})})
                response = connection.getresponse()
                result = (response.status, dict(response.getheaders()), response.read().decode())
                connection.close()
                return result

            for _ in range(60):
                try:
                    first = request()
                    if first[0] == 200:
                        break
                except (OSError, http.client.HTTPException):
                    pass
                time.sleep(0.25)
            else:
                raise AssertionError("Runtime did not become ready")

            assert "php-index" in first[2] and "<?php" not in first[2], first
            cookie = first[1]["Set-Cookie"].split(";", 1)[0]
            result = request("/success.php?campaign=42", "POST", "name=Ivan&empty=&nested[a]=b&phone=%20%2B123%20", {
                "Cookie": cookie, "Content-Type": "application/x-www-form-urlencoded"})
            data = json.loads(result[2])
            assert result[0] == 201 and result[1]["X-Landing-Test"] == "executed", result
            assert result[1]["Cache-Control"] == "no-store, private", result
            assert data == {"method": "POST", "get": {"campaign": "42"},
                            "post": {"name": "Ivan", "empty": "", "nested": {"a": "b"}, "phone": " +123 "},
                            "visits": 1}, data
            assert request("/nested/")[2] == "nested-php"
            directory_redirect = request("/nested?campaign=42", "POST", "x=1")
            assert directory_redirect[0] == 308 and directory_redirect[1]["Location"] == "/nested/?campaign=42", directory_redirect
            redirect = request("/redirect.php", "POST", "name=Ivan")
            assert redirect[0] == 303 and redirect[1]["Location"] == "/success.php?sent=1", redirect
            assert request("/static.html")[2] == "static-page"
            assert request("/style.css")[1]["Cache-Control"] == "public, no-cache"
            assert request("/static.html", "POST", "x=1")[0] == 405
            assert request("/extensionless-route")[0] == 404
            assert request("/extensionless-route", host="static.test")[2] == "static-fallback"
            for source in ("missing.php", "missing.css", "secret.tpl.php", "secret.tpl.html", "secret.inc", "include.inc.php", "secret.PHP", "secret.php.bak", ".env", "secret.sql", "secret.php5", "success.php/extra"):
                denied = request("/" + source)
                assert denied[0] == 404 and "never-download" not in denied[2], (source, denied)

            boundary = "LandingRuntimeTestBoundary"
            multipart = ("--" + boundary + '\r\nContent-Disposition: form-data; name="name"\r\n\r\nIvan\r\n'
                         "--" + boundary + '\r\nContent-Disposition: form-data; name="attachment"; filename="hello.txt"\r\n'
                         'Content-Type: text/plain\r\n\r\nhello-upload\r\n--' + boundary + "--\r\n")
            uploaded = request("/upload.php", "POST", multipart, {"Content-Type": "multipart/form-data; boundary=" + boundary})
            assert json.loads(uploaded[2]) == {"name": "hello.txt", "contents": "hello-upload", "moved": True, "post": {"name": "Ivan"}}, uploaded
            isolated = json.loads(request("/isolation.php")[2])
            assert all(value is False for key, value in isolated.items() if key != "tmp"), isolated
            assert isolated["tmp"].startswith("/tmp/sites/"), isolated
            assert request(host="inactive.test")[0] == 404

            docker("exec", writer, "ln", "-s", "../releases/second", "/srv/landings/hosts/.next")
            docker("exec", writer, "mv", "-Tf", "/srv/landings/hosts/.next", "/srv/landings/hosts/site.test")
            activated = request()
            assert activated[2] == "second-release", ("Activation used an old cached script", activated)
            docker("exec", writer, "ln", "-s", "../releases/first", "/srv/landings/hosts/.next")
            docker("exec", writer, "mv", "-Tf", "/srv/landings/hosts/.next", "/srv/landings/hosts/site.test")
            assert "php-index" in request()[2], "Rollback used an old cached script"
            docker("exec", writer, "rm", "/srv/landings/hosts/site.test")
            assert request()[0] == 404
            docker("exec", writer, "ln", "-s", "../releases/first", "/srv/landings/hosts/site.test")
            docker("stop", runtime)
            offline = request("/index.php")
            assert offline[0] == 502 and "<?php" not in offline[2], offline
            assert request("/style.css")[0] == 200
            print("PASS: PHP indexes, POST/query/session/header/redirect, multipart upload, static/SPA behavior,")
            print("      source denial, filesystem boundary, active host switch/rollback, FPM outage fail-closed.")
        except Exception:
            for container in (runtime, edge):
                try:
                    logs = subprocess.run(["docker", "logs", "--tail", "40", container], text=True, capture_output=True)
                    print(logs.stdout + logs.stderr)
                except RuntimeError:
                    pass
            raise
        finally:
            for container in (edge, runtime, writer):
                subprocess.run(["docker", "rm", "-f", container], capture_output=True)
            for volume in (hosts_volume, releases_volume):
                subprocess.run(["docker", "volume", "rm", volume], capture_output=True)
            subprocess.run(["docker", "network", "rm", name], capture_output=True)


if __name__ == "__main__":
    main()
