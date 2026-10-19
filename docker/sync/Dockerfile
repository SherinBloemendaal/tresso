# syntax=docker/dockerfile:1
FROM golang:1.27-alpine AS dev
WORKDIR /src
# Source is bind-mounted; module and build caches live in named volumes.
CMD ["go", "run", "./cmd/sync"]

FROM golang:1.27-alpine AS build
WORKDIR /src
COPY apps/sync/go.mod apps/sync/go.sum ./
RUN go mod download
COPY apps/sync/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/sync ./cmd/sync

FROM gcr.io/distroless/static-debian13:nonroot AS prod
COPY --from=build /out/sync /sync
EXPOSE 8081
ENTRYPOINT ["/sync"]
