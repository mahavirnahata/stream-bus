const { createClient } = require('redis');

const STREAM = 'stream-bus:events:outbound';
const GROUP = 'workers';
const CONSUMER = 'node-1';

async function main() {
  const client = createClient();
  await client.connect();

  // Create group if missing
  try {
    await client.xGroupCreate(STREAM, GROUP, '0', { MKSTREAM: true });
  } catch (e) {
    // group likely exists
  }

  while (true) {
    const res = await client.xReadGroup(
      GROUP,
      CONSUMER,
      [{ key: STREAM, id: '>' }],
      { COUNT: 1, BLOCK: 5000 }
    );

    if (!res) continue;

    for (const stream of res) {
      for (const message of stream.messages) {
        const payload = JSON.parse(message.message.message || '{}');
        console.log('got', payload);

        // TODO: process, then ACK
        await client.xAck(STREAM, GROUP, message.id);
      }
    }
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
