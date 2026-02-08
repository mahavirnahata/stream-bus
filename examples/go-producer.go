package main

import (
    "context"
    "encoding/json"
    "fmt"
    "time"

    "github.com/redis/go-redis/v9"
)

const Stream = "stream-bus:events:outbound"

func main() {
    ctx := context.Background()
    rdb := redis.NewClient(&redis.Options{Addr: "127.0.0.1:6379"})

    payload := map[string]any{
        "id": fmt.Sprintf("%d", time.Now().UnixNano()),
        "ts": time.Now().Unix(),
        "payload": map[string]any{"type": "image.process", "id": 123},
    }

    raw, _ := json.Marshal(payload)

    _, err := rdb.XAdd(ctx, &redis.XAddArgs{
        Stream: Stream,
        ID:     "*",
        Values: map[string]any{"message": string(raw)},
    }).Result()

    if err != nil {
        panic(err)
    }

    fmt.Println("sent", string(raw))
}
