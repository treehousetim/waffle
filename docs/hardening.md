# Hardening the server

What a new droplet gets before anything is deployed to it, once, as root. It
is server config, like the poller and the front Caddy: done by hand, never by
a release. docs/fresh-install.md, step 8, points here.

| What | Why |
| --- | --- |
| A DigitalOcean cloud firewall: 22, 80 and 443 (and 443/udp) in, nothing else | Outside the droplet, so nothing on it can open a port by mistake. Docker writes its own iptables rules for published ports, past any firewall on the host, so the cloud firewall is the one that holds. |
| SSH by key only, root by key only, no forwarding, 3 tries | Passwords cannot be guessed if none are accepted. Nothing here needs X11, agent or port forwarding. |
| No LLMNR or multicast DNS | systemd-resolved answers name lookups on port 5355 from anywhere by default; a server has no use for it. |
| Debian's security updates install every day, and reboot at 4:30 am when one needs it | A new kernel does nothing until a reboot. The app, and any site beside it, is down for about a minute and comes back on its own (`restart: unless-stopped`). |
| The server's clock in your time zone | So 4:30 am, the crontab and the logs all mean local time. The app keeps its own times in UTC whatever this says. |
| A 2 GB swap file | 2 GB of memory is enough to run, not always to build the PHP image as well. |
| Docker's logs capped at 3 × 10 MB per container, and the journal at 500 MB | Logs never fill the disk. |
| `jq` | The poller reads GitHub's answers with it. |

Docker CE and the Doppler CLI come from their own apt repositories, which the
daily updates leave alone: a Docker upgrade restarts every container. Upgrade
them by hand (`apt update && apt upgrade`) when their releases say to.

There is no `fail2ban`: with passwords off, a guessed login cannot succeed,
and the cloud firewall is the only thing that decides what reaches the
server.

## The commands

**1. The cloud firewall**, from your workstation (`doctl`, signed in to the
account the droplet is on):

```bash
doctl compute firewall create --name waffle \
  --inbound-rules "protocol:tcp,ports:22,address:0.0.0.0/0,address:::/0 protocol:tcp,ports:80,address:0.0.0.0/0,address:::/0 protocol:tcp,ports:443,address:0.0.0.0/0,address:::/0 protocol:udp,ports:443,address:0.0.0.0/0,address:::/0" \
  --outbound-rules "protocol:tcp,ports:all,address:0.0.0.0/0,address:::/0 protocol:udp,ports:all,address:0.0.0.0/0,address:::/0 protocol:icmp,address:0.0.0.0/0,address:::/0" \
  --droplet-ids <the droplet's ID>
```

**2. SSH**, as root on the server. A file that sorts first, because the first
value sshd reads for a setting is the one it keeps:

```bash
cat > /etc/ssh/sshd_config.d/01-hardening.conf <<'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding no
PermitTunnel no
EOF
sshd -t && systemctl reload ssh
```

Keep this session open and log in again from a second terminal before you
close it: a mistake here is fixed from the session you still have.

**3. Name lookups, the clock, updates, swap, logs:**

```bash
mkdir -p /etc/systemd/resolved.conf.d /etc/systemd/journald.conf.d /etc/docker
printf '[Resolve]\nLLMNR=no\nMulticastDNS=no\n' > /etc/systemd/resolved.conf.d/10-no-llmnr.conf
systemctl restart systemd-resolved

timedatectl set-timezone America/Chicago      # yours: timedatectl list-timezones

cat > /etc/apt/apt.conf.d/52unattended-upgrades-local <<'EOF'
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "04:30";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF

fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' > /etc/sysctl.d/90-swap.conf && sysctl -q --system

printf '[Journal]\nSystemMaxUse=500M\n' > /etc/systemd/journald.conf.d/10-size.conf
systemctl restart systemd-journald

cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "live-restore": true
}
EOF
systemctl restart docker

apt-get install -y jq
```

`live-restore` keeps containers running while the Docker daemon itself
restarts.

## Checking it

```bash
ss -tulpn                          # listening beyond loopback: sshd on 22 only, until the front Caddy is up
sshd -T | grep -E '^(permitrootlogin|passwordauthentication|x11forwarding|allowtcpforwarding|maxauthtries) '
swapon --show
timedatectl | grep 'Time zone'
cat /etc/apt/apt.conf.d/20auto-upgrades     # Update-Package-Lists and Unattended-Upgrade both "1"
```

and from your workstation, `doctl compute firewall list` shows the firewall
with the droplet's ID.
