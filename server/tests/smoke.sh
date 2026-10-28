#!/usr/bin/env bash
# Smoke test of the PHP backend: login, admin tabs, token feed, booking, message, ICS, FAQ fallback, voice import.
# Needs: php (8.1+), curl, python3. Usage: server/tests/smoke.sh   (uses port 8099, a temp folder, a fake sendmail)
set -u
HERE="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8099}"
T="$(mktemp -d)"
PRIV="$T/private"; mkdir -p "$PRIV" "$T/mails"
HASH="$(php -r 'echo password_hash("test-password", PASSWORD_DEFAULT);')"
cat > "$T/config.php" <<PHP
<?php return [
  'site_url' => 'http://127.0.0.1:$PORT', 'owner_name' => 'Jane Doe', 'assistant_name' => 'Jane',
  'notify_email' => 'owner@example.com', 'from_email' => 'assistant@example.com', 'timezone' => 'Europe/Berlin',
  'private_dir' => '$PRIV', 'site_config_file' => '$T/site-config.json', 'status_url' => 'http://127.0.0.1:1/none',
  'faq_links' => ['en' => ['about' => '/about/']],
  'admin' => ['username' => 'admin', 'password_hash' => '$HASH'],
];
PHP
cat > "$T/sendmail.sh" <<SH
#!/bin/bash
cat > "$T/mails/\$(date +%s%N).eml"
SH
chmod +x "$T/sendmail.sh"
AIS_CONFIG="$T/config.php" php -d sendmail_path="$T/sendmail.sh -t" -d upload_max_filesize=16M -d post_max_size=16M -S "127.0.0.1:$PORT" -t "$HERE" >"$T/php.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/login.php" && break; sleep 0.2; done

B="http://127.0.0.1:$PORT"; J="$T/cj"; FAIL=0
ok()   { echo "  ok   $1"; }
bad()  { echo "  FAIL $1"; FAIL=$((FAIL+1)); }
expect(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got '$2', wanted '$3')"; fi; }
has()  { if printf '%s' "$2" | grep -q -- "$3"; then ok "$1"; else bad "$1 (missing '$3')"; fi; }
jget() { python3 -c "import sys,json;d=json.load(sys.stdin);print($1)" 2>/dev/null; }

echo "== login"
curl -s -c "$J" -b "$J" "$B/admin/login.php" -o "$T/l.html"
CS=$(grep -o 'name="csrf" value="[^"]*"' "$T/l.html" | head -1 | sed 's/.*value="//;s/"//')
expect "wrong password rejected" "$(curl -s -c "$J" -b "$J" -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$CS" -d user=admin -d pass=nope "$B/admin/login.php")" 200
expect "admin page redirects when logged out" "$(curl -s -o /dev/null -w '%{http_code}' "$B/admin/chatbot.php")" 302
expect "login succeeds" "$(curl -s -c "$J" -b "$J" -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$CS" -d user=admin -d pass=test-password "$B/admin/login.php")" 302

echo "== admin tabs"
for t in status widget behavior voice knowledge booking mail limits; do
  code=$(curl -s -c "$J" -b "$J" -o "$T/t.html" -w '%{http_code}' "$B/admin/chatbot.php?t=$t")
  expect "tab $t renders" "$code" 200
done
curl -s -c "$J" -b "$J" "$B/admin/chatbot.php" -o "$T/p.html"
CS=$(grep -o 'name="csrf" value="[^"]*"' "$T/p.html" | head -1 | sed 's/.*value="//;s/"//')
post() { t=$1; shift; curl -s -c "$J" -b "$J" -o "$T/o.html" "$B/admin/chatbot.php?t=$t" --data-urlencode "csrf=$CS" "$@"; grep -o 'class="panel msg">[^<]*' "$T/o.html" | sed 's/.*>//' | head -1; }
has "token generated" "$(post status -d a=token)" "Token generated"
TOK=$(cat "$PRIV/assistant-token")
has "widget saved" "$(post widget -d a=widget -d chat=1 -d chatVoice=1 -d chatSide=left --data-urlencode chatName=Jane -d chatBase=/assistant)" "Widget saved"
expect "site-config.json written (chat on)" "$(jget "d['chat']" < "$T/site-config.json")" True
has "booking enabled" "$(post booking -d a=booking -d enabled=1 -d durationMin=20 -d bufferMin=10 -d noticeH=0 -d horizonD=10 -d h_mon='08:00-18:00' -d h_tue='08:00-18:00' -d h_wed='08:00-18:00' -d h_thu='08:00-18:00' -d h_fri='08:00-18:00' -d h_sat='08:00-18:00' -d h_sun='08:00-18:00' --data-urlencode place='Video call' -d confirmVisitor=1)" "Saved"
has "mail settings saved" "$(post mail -d a=mail -d messages=1 --data-urlencode notify=owner@example.com)" "Saved"
has "behavior saved" "$(post behavior -d a=behavior -d style=formal -d length=short -d 'langs[]=en' -d 'langs[]=it' --data-urlencode g_en='Hello from the test' --data-urlencode contact=owner@example.com)" "Saved"
has "limits saved" "$(post limits -d a=limits -d perHour=0 -d perDay=50 -d durationMin=5 -d idleSec=30 -d pauseSec=0)" "Saved"

echo "== feed (token)"
expect "feed without token is 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/assistant-config.php")" 404
curl -s -H "X-Assistant-Token: $TOK" "$B/assistant-config.php" -o "$T/feed.json"
expect "feed enabled" "$(jget "d['bookingFeed']['enabled']" < "$T/feed.json")" True
expect "feed has slots" "$(jget "len(d['bookingFeed']['slots'])>0" < "$T/feed.json")" True
expect "feed carries settings" "$(jget "d['style']+d['length']+d['greeting']['en']" < "$T/feed.json")" "formalshortHello from the test"
expect "feed hides mail addresses" "$(jget "'booking' in d or 'mail' in d" < "$T/feed.json")" False

echo "== booking + message"
SLOT=$(jget "d['bookingFeed']['slots'][0]['date']+' '+d['bookingFeed']['slots'][0]['time']" < "$T/feed.json"); D=${SLOT% *}; H=${SLOT#* }
api() { curl -s -X POST -H "X-Assistant-Token: $TOK" -H 'Content-Type: application/json' "$B/assistant-booking.php" -d "$1"; }
R=$(api "{\"type\":\"booking\",\"name\":\"Anna Test\",\"email\":\"anna@example.com\",\"date\":\"$D\",\"time\":\"$H\",\"note\":\"Cloud role\",\"lang\":\"de\",\"ip\":\"203.0.113.5\"}")
expect "booking ok" "$(printf '%s' "$R" | jget "d['ok']")" True
BID=$(printf '%s' "$R" | jget "d['id']")
expect "same slot is refused" "$(api "{\"type\":\"booking\",\"name\":\"Bob\",\"email\":\"bob@example.com\",\"date\":\"$D\",\"time\":\"$H\",\"lang\":\"en\"}" | jget "d['error']")" slot_taken
expect "message ok" "$(api '{"type":"message","name":"Carla","email":"carla@example.com","message":"Hello, we have a role for you.","lang":"it"}' | jget "d['ok']")" True
expect "bad token is 404" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'X-Assistant-Token: x' "$B/assistant-booking.php" -d '{}')" 404
expect "3 mails sent (owner+visitor+message)" "$(ls "$T/mails" | wc -l | tr -d ' ')" 3

echo "== calendar (ICS)"
has "ics token generated" "$(post booking -d a=caltoken)" "Calendar link generated"
CT=$(cat "$PRIV/assistant-cal-token")
has "ics feed lists the booking" "$(curl -s "$B/assistant-calendar.php?k=$CT")" "SUMMARY:Appointment: Anna Test"
expect "ics feed without token is 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/assistant-calendar.php?k=wrong")" 404
SIG=$(php -r 'echo substr(hash_hmac("sha256", $argv[1], $argv[2]."|cal"), 0, 24);' "$BID" "$TOK")
has "single appointment link works" "$(curl -s "$B/assistant-calendar.php?b=$BID&h=$SIG")" "BEGIN:VEVENT"

echo "== FAQ fallback"
faq() { curl -s -X POST -H 'Content-Type: application/json' "$B/assistant-faq.php" -d "{\"lang\":\"$1\",\"text\":\"$2\"}"; }
has "answers a contact question" "$(faq en 'How can I contact Jane?' | jget "d['reply']")" "owner@example.com"
has "greeting uses the owner name" "$(faq de 'Hallo' | jget "d['reply']")" "Jane Doe"
has "unknown question falls back" "$(faq it 'ricetta della carbonara' | jget "d['reply']")" "owner@example.com"
has "link chips come from config" "$(faq en 'who is she' | jget "d['links'][0]['href']")" "/about/"
expect "foreign origin is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Origin: https://evil.example' "$B/assistant-faq.php" -d '{"text":"hi"}')" 403

echo "== voice import"
python3 - "$T/v.wav" <<'PY'
import sys,wave,struct,math
w=wave.open(sys.argv[1],'wb'); w.setnchannels(1); w.setsampwidth(2); w.setframerate(24000)
w.writeframes(b''.join(struct.pack('<h',int(8000*math.sin(i/12.0))) for i in range(48000))); w.close()
PY
TXT="Hello, this is a test sentence that is long enough to be accepted by the form."
has "needs consent" "$(curl -s -c "$J" -b "$J" "$B/admin/chatbot.php?t=voice" -F "csrf=$CS" -F a=voiceup -F vname=test -F "vtext=$TXT" -F "vfile=@$T/v.wav" | grep -o 'class="panel msg">[^<]*')" "Confirm that the voice"
has "upload accepted" "$(curl -s -c "$J" -b "$J" "$B/admin/chatbot.php?t=voice" -F "csrf=$CS" -F a=voiceup -F vname=Test-Voice -F consent=1 -F "vtext=$TXT" -F "vfile=@$T/v.wav" | grep -o 'class="panel msg">[^<]*')" "Voice sent"
curl -s -H "X-Assistant-Token: $TOK" "$B/assistant-config.php" -o "$T/feed.json"
VID=$(jget "d['voiceImports'][0]['id']" < "$T/feed.json")
expect "pending voice in the feed" "$(jget "d['voiceImports'][0]['name']" < "$T/feed.json")" test-voice
expect "engine can download it" "$(curl -s -H "X-Assistant-Token: $TOK" "$B/assistant-voice.php?id=$VID" | wc -c | tr -d ' ')" "$(wc -c < "$T/v.wav" | tr -d ' ')"
expect "download needs the token" "$(curl -s -o /dev/null -w '%{http_code}' "$B/assistant-voice.php?id=$VID")" 404
expect "status report accepted" "$(curl -s -X POST -H "X-Assistant-Token: $TOK" "$B/assistant-voice.php" -d "{\"id\":\"$VID\",\"status\":\"installed\"}" | jget "d['ok']")" True
curl -s -c "$J" -b "$J" "$B/admin/chatbot.php?t=voice" | grep -q 'installed' && ok "admin shows it as installed" || bad "admin shows it as installed"

echo "== static checks"
[ "$(curl -s "$B/admin/booking-lib.php" -o /dev/null -w '%{http_code}')" = 404 ] && ok "lib files answer 404 when requested directly" || bad "lib files answer 404 when requested directly"

echo
if [ "$FAIL" = 0 ]; then echo "ALL OK"; else echo "$FAIL FAILED"; tail -5 "$T/php.log"; fi
exit "$FAIL"
