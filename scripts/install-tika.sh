#!/bin/bash
# Tika Server Installation Script
# Runs on its own port (9998) - independent of nginx/apache

set -e

TIKA_VERSION="2.9.2"
TIKA_DIR="/opt/tika"
TIKA_PORT="9998"

echo "=== Installing OpenJDK 17 ==="
sudo apt-get update
sudo apt-get install -y openjdk-17-jre-headless

echo "=== Creating Tika directory ==="
sudo mkdir -p "$TIKA_DIR"
sudo chown $USER:$USER "$TIKA_DIR"

echo "=== Downloading Apache Tika Server $TIKA_VERSION ==="
cd "$TIKA_DIR"
wget -q "https://archive.apache.org/dist/tika/${TIKA_VERSION}/tika-server-standard-${TIKA_VERSION}.jar" \
    -O tika-server.jar

echo "=== Creating systemd service ==="
sudo tee /etc/systemd/system/tika.service > /dev/null << EOF
[Unit]
Description=Apache Tika Server
After=network.target

[Service]
Type=simple
User=$USER
WorkingDirectory=$TIKA_DIR
ExecStart=/usr/bin/java -jar $TIKA_DIR/tika-server.jar --host=127.0.0.1 --port=$TIKA_PORT
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

echo "=== Enabling and starting Tika service ==="
sudo systemctl daemon-reload
sudo systemctl enable tika
sudo systemctl start tika

echo "=== Waiting for Tika to start ==="
sleep 5

echo "=== Testing Tika ==="
if curl -s "http://127.0.0.1:$TIKA_PORT/tika" > /dev/null; then
    echo "SUCCESS: Tika is running on port $TIKA_PORT"
    curl -s "http://127.0.0.1:$TIKA_PORT/version"
    echo ""
else
    echo "WARNING: Tika may still be starting. Check with: sudo systemctl status tika"
fi

echo ""
echo "=== Installation Complete ==="
echo "Tika API: http://127.0.0.1:$TIKA_PORT"
echo "Test: curl -T /path/to/file.pdf http://127.0.0.1:$TIKA_PORT/tika"
echo "Status: sudo systemctl status tika"
echo "Logs: sudo journalctl -u tika -f"
