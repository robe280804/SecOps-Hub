FROM alpine:3.23
RUN apk add --no-cache python3 py3-aiohttp docker-cli ttyd tini
WORKDIR /app
COPY gateway.py test_gateway.py ./
RUN python3 -m unittest -v test_gateway
EXPOSE 7681
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["python3", "gateway.py"]
