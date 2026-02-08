package main

import (
    "context"
    "encoding/json"
    "fmt"
    "log"

    "github.com/redis/go-redis/v9"
)

const (
    Stream   = "stream-bus:events:outbound"
    Group    = "workers"
    Consumer = "go-1"
)

func main() {
    ctx := context.Background()
    rdb := redis.NewClient(&redis.Options{Addr: "127.0.0.1:6379"})

    // Create group if missing
    if err := rdb.XGroupCreateMkStream(ctx, Stream, Group, "0").Err(); err != nil {
        // likely group exists
    }

    for {
        res, err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{
            Group:    Group,
            Consumer: Consumer,
            Streams:  []string{Stream, ">"},
            Count:    1,
            Block:    5000,
        }).Result()
        if err != nil {
            if err == redis.Nil {
                continue
            }
            log.Fatal(err)
        }

        for _, stream := range res {
            for _, msg := range stream.Messages {
                raw := msg.Values["message"].(string)
                var payload map[string]any
                _ = json.Unmarshal([]byte(raw), &payload)

                fmt.Println("got", payload)

                // TODO: process, then ACK
                _ = rdb.XAck(ctx, Stream, Group, msg.ID).Err()
            }
        }
    }
}
