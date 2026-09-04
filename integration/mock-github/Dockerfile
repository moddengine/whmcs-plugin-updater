FROM python:3.12-alpine

RUN apk add --no-cache openssl
WORKDIR /app
COPY server.py start.sh ./
RUN chmod 0755 start.sh
ENTRYPOINT ["./start.sh"]
