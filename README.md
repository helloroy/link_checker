# PHP Link Checker

A PHP CLI tool for deep-crawl link validation. It recursively explores URLs to detect broken links, PHP errors, and identify HTML structural issues.

## 🚀 Quick Start
```bash
php link_checker.php https://example.com
```

```
Usage: php check.php [URL] [Options]
Options:
  -d, --depth <int>    Max crawling depth (Default: 1)
  -e, --external <0|1> Check external links (Default: 0)
  -h, --html <0|1>     Check HTML structure (Default: 0)
  -o, --output <file>  Log file path (Default: link_checker_report.txt)
```
