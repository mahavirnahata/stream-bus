const { createClient } = require('redis');
const crypto = require('crypto');

const STREAM = 'stream-bus:events:outbound';

async function main() {
  const client = createClient();
  await client.connect();

  const message = {
    id: crypto.randomUUID(),
    ts: Math.floor(Date.now() / 1000),
    payload: { type: 'image.process', id: 123 }
  };

  await client.xAdd(STREAM, '*', { message: JSON.stringify(message) });
  console.log('sent', message);

  await client.quit();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
