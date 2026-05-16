import json
import time
import uuid
import redis

STREAM = 'stream-bus:events:outbound'

r = redis.Redis()

message = {
    'id': str(uuid.uuid4()),
    'ts': int(time.time()),
    'payload': {'type': 'image.process', 'id': 123}
}

r.xadd(STREAM, {'message': json.dumps(message)})
print('sent', message)
