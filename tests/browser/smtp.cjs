const fs = require('node:fs');
const net = require('node:net');

// A loopback-only inbox. Messages are captured locally and are never relayed.
async function startInbox(mailPath) {
    fs.writeFileSync(mailPath, '[]', {mode: 0o600});
    const clients = new Set();
    const server = net.createServer(socket => {
        clients.add(socket);
        socket.on('close', () => clients.delete(socket));
        socket.on('error', () => {});
        socket.setEncoding('utf8');
        socket.write('220 localhost browser inbox\r\n');
        let buffer = '', data = null;
        socket.on('data', chunk => {
            buffer += chunk;
            if (buffer.length > 1024 * 1024) {socket.destroy(); return;}
            let end;
            while ((end = buffer.indexOf('\n')) !== -1) {
                const line = buffer.slice(0, end).replace(/\r$/, '');
                buffer = buffer.slice(end + 1);
                if (data !== null) {
                    if (line === '.') {
                        const messages = JSON.parse(fs.readFileSync(mailPath));
                        messages.push(data.join('\r\n'));
                        fs.writeFileSync(mailPath, JSON.stringify(messages));
                        data = null;
                        socket.write('250 Message captured\r\n');
                    } else {
                        data.push(line.replace(/^\.\./, '.'));
                    }
                } else if (/^(EHLO|HELO)\b/i.test(line)) {
                    socket.write('250 localhost\r\n');
                } else if (/^(MAIL FROM:|RCPT TO:|RSET|NOOP)/i.test(line)) {
                    socket.write('250 OK\r\n');
                } else if (/^DATA$/i.test(line)) {
                    data = [];
                    socket.write('354 End with a dot\r\n');
                } else if (/^QUIT$/i.test(line)) {
                    socket.end('221 Bye\r\n');
                } else {
                    socket.write('502 Unsupported command\r\n');
                }
            }
        });
    });
    await new Promise((resolve, reject) => {server.once('error', reject); server.listen(0, '127.0.0.1', resolve);});
    return {
        port: server.address().port,
        close: () => new Promise(resolve => {for (const socket of clients) socket.destroy(); server.close(resolve);}),
    };
}

module.exports = {startInbox};
