FROM mcr.microsoft.com/playwright:v1.60.0-noble

WORKDIR /tests
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY playwright.config.js update-flow.spec.js ./

ENTRYPOINT ["npx", "playwright", "test"]
