# Google News Topic -> Original Articles RSS

This tool fetches a Google News Topic feed, filters and deduplicates items, ranks the latest, generates original 600–800 word articles using OpenAI, finds a relevant image from Google Images (width 1000–1600), and outputs an RSS feed. Each RSS item is streamed immediately when ready.

## Requirements

- PHP 8.0+ with curl and XML extensions
- Environment variables:
  - `OPENAI_API_KEY` (required) – for generating original articles
  - `SERPAPI_API_KEY` (optional) – improves Google Images lookup reliability

## Install (Ubuntu/Debian)

```bash
sudo apt-get update -y
sudo apt-get install -y php php-curl php-xml
```

## Run

```bash
export OPENAI_API_KEY=your_key_here
php /workspace/news_to_rss.php > feed.xml
```

Optionally pass a topic URL (defaults to the one provided):

```bash
php /workspace/news_to_rss.php "https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNREpxYW5RU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen" > feed.xml
```

The script writes RSS to stdout and streams `<item>` elements as they are completed.

## Notes

- Filtering removes horoscopes and deduplicates by similarity and shared entities.
- Categories are selected from a fixed allowed list.
- Articles are formatted using only `<p>`, `<h3>`, and `<i>` tags. The first paragraph has no preceding headline.
- A relevant image is fetched from Google Images; if `SERPAPI_API_KEY` is set, it will be used first.
