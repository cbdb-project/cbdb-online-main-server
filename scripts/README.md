# CBDB Scripts

[中文說明](./README.zh.md)

This directory contains utility scripts for the CBDB Online project.

## fetch_wikidata_cbdb.py

This script queries the Wikidata SPARQL endpoint to fetch person records that have CBDB person IDs and outputs them in a structured JSON format.

### Requirements

- Python 3.6+
- `requests` library

Install requirements:
```bash
pip install requests
```

### Usage

```bash
# Basic usage - outputs to wikidata_cbdb_persons.json
python3 scripts/fetch_wikidata_cbdb.py

# Specify custom output file
python3 scripts/fetch_wikidata_cbdb.py -o custom_output.json

# Enable verbose output
python3 scripts/fetch_wikidata_cbdb.py --verbose
```

### Output Format

The script generates a JSON file with the following structure:

```json
{
  "generated_at": "2025-11-05T00:00:00Z",
  "source": "Wikidata SPARQL",
  "schema_version": 1,
  "records": [
    {
      "cbdb_personid": 12345,
      "wikidata_qid": "Q125054",
      "wikipedia": {
        "zh": "司马光",
        "en": "Sima_Guang"
      }
    }
  ]
}
```

### Features

- Queries all persons in Wikidata with CBDB person IDs (property P497)
- Extracts Chinese and English Wikipedia article titles when available
- Includes retry logic for network failures
- Provides detailed statistics about the fetched data
- Handles errors gracefully and provides informative output

### Notes

- The script respects Wikidata's query limits and includes appropriate delays
- Wikipedia article titles are URL-decoded for better readability
- Only persons that are instances of "human" (Q5) are included
- Results are sorted by CBDB person ID for consistency
