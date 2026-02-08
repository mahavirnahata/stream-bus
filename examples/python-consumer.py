import json
import redis

STREAM = 'stream-bus:events:outbound'
GROUP = 'workers'
CONSUMER = 'py-1'

r = redis.Redis()

# Create group if missing
try:
    r.xgroup_create(STREAM, GROUP, id='0', mkstream=True)
except Exception:
    pass

while True:
    res = r.xreadgroup(GROUP, CONSUMER, {STREAM: '>'}, count=1, block=5000)
    if not res:
        continue

    for stream, messages in res:
        for message_id, fields in messages:
            payload = json.loads(fields.get(b'message', b'{}'))
            print('got', payload)

            # TODO: process, then ACK
            r.xack(STREAM, GROUP, message_id)
