"""Minimal SMTP sink for local testing: accepts every message, appends it to a file."""
import socketserver, sys, base64, email

OUT = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 2525

class Handler(socketserver.StreamRequestHandler):
    def send(self, line):
        self.wfile.write((line + '\r\n').encode())

    def handle(self):
        self.send('220 sink ready')
        rcpt, data_mode, data = [], False, []
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            line = raw.decode('utf-8', 'replace').rstrip('\r\n')
            if data_mode:
                if line == '.':
                    msg = email.message_from_string('\n'.join(data))
                    body = msg.get_payload(decode=True).decode('utf-8', 'replace')
                    subj = str(email.header.make_header(email.header.decode_header(msg['Subject'])))
                    with open(OUT, 'a', encoding='utf-8') as f:
                        f.write(f"=== TO: {','.join(rcpt)}\nSUBJECT: {subj}\n{body}\n")
                    data_mode, data, rcpt = False, [], []
                    self.send('250 queued')
                else:
                    data.append(line[1:] if line.startswith('..') else line)
                continue
            cmd = line[:4].upper()
            if cmd in ('EHLO', 'HELO'): self.send('250 sink')
            elif cmd == 'MAIL': self.send('250 ok')
            elif cmd == 'RCPT': rcpt.append(line.split(':', 1)[1].strip(' <>')); self.send('250 ok')
            elif cmd == 'DATA': data_mode = True; self.send('354 go')
            elif cmd == 'QUIT': self.send('221 bye'); return
            else: self.send('250 ok')

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True

Server(('127.0.0.1', PORT), Handler).serve_forever()
