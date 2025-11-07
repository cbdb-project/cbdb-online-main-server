#!/usr/bin/env python3
"""
Wikidata CBDB Person Data Fetcher

This script queries the Wikidata SPARQL endpoint to fetch person records
that have CBDB person IDs and outputs them in a structured JSON format.
"""

import json
import sys
import time
from datetime import datetime, timezone
from typing import Dict, List, Optional, Any
import requests
from urllib.parse import quote


class WikidataCBDBFetcher:
    """Fetcher for CBDB person data from Wikidata."""

    def __init__(self):
        self.endpoint = "https://query.wikidata.org/sparql"
        self.user_agent = "CBDB-Wikidata-Fetcher/1.0 (https://cbdb.fas.harvard.edu/)"
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': self.user_agent,
            'Accept': 'application/sparql-results+json'
        })

    def get_sparql_query(self) -> str:
        """
        SPARQL query to fetch persons with CBDB IDs and their Wikipedia articles.

        This query finds:
        - Items that are instances of human (Q5)
        - Have a CBDB person ID (P497)
        - Optional: Chinese Wikipedia sitelink
        - Optional: English Wikipedia sitelink
        - Optional: Japanese Wikipedia sitelink
        """
        return """
        SELECT DISTINCT ?person ?cbdb_id ?zh_title ?en_title ?ja_title WHERE {
          ?person wdt:P31 wd:Q5 .
          ?person wdt:P497 ?cbdb_id .

          OPTIONAL {
            ?zh_article schema:about ?person ;
                       schema:isPartOf <https://zh.wikipedia.org/> ;
                       schema:name ?zh_title .
          }

          OPTIONAL {
            ?en_article schema:about ?person ;
                       schema:isPartOf <https://en.wikipedia.org/> ;
                       schema:name ?en_title .
          }

          OPTIONAL {
            ?ja_article schema:about ?person ;
                       schema:isPartOf <https://ja.wikipedia.org/> ;
                       schema:name ?ja_title .
          }
        }
        ORDER BY ?cbdb_id
        """

    def execute_query(self, query: str, max_retries: int = 3) -> Optional[Dict[str, Any]]:
        """Execute SPARQL query with retry logic."""
        for attempt in range(max_retries):
            try:
                print(f"Executing SPARQL query (attempt {attempt + 1}/{max_retries})...")

                response = self.session.get(
                    self.endpoint,
                    params={'query': query, 'format': 'json'},
                    timeout=30
                )
                response.raise_for_status()

                data = response.json()
                print(f"Query successful. Found {len(data.get('results', {}).get('bindings', []))} results.")
                return data

            except requests.exceptions.RequestException as e:
                print(f"Request failed (attempt {attempt + 1}): {e}")
                if attempt < max_retries - 1:
                    print(f"Retrying in {2 ** attempt} seconds...")
                    time.sleep(2 ** attempt)
                else:
                    print("Max retries exceeded.")
                    return None

        return None

    def parse_results(self, sparql_results: Dict[str, Any]) -> List[Dict[str, Any]]:
        """Parse SPARQL results into the desired JSON format."""
        records = []
        bindings = sparql_results.get('results', {}).get('bindings', [])

        for binding in bindings:
            try:
                # Extract Wikidata QID from URI
                person_uri = binding.get('person', {}).get('value', '')
                qid = person_uri.split('/')[-1] if person_uri else None

                # Extract CBDB person ID
                cbdb_id = binding.get('cbdb_id', {}).get('value', '')

                # Skip if essential data is missing
                if not qid or not cbdb_id:
                    continue

                # Try to parse CBDB ID as integer
                try:
                    cbdb_personid = int(cbdb_id)
                except ValueError:
                    print(f"Warning: Invalid CBDB ID format: '{cbdb_id}' for Wikidata entity {qid}")
                    continue

                # Extract Wikipedia article titles (原始UTF-8标题)
                wikipedia = {}

                zh_title = binding.get('zh_title', {}).get('value', '')
                if zh_title:
                    wikipedia['zh'] = zh_title

                en_title = binding.get('en_title', {}).get('value', '')
                if en_title:
                    wikipedia['en'] = en_title

                ja_title = binding.get('ja_title', {}).get('value', '')
                if ja_title:
                    wikipedia['ja'] = ja_title

                # Build record - only include wikipedia field if it has content
                record = {
                    'cbdb_personid': cbdb_personid,
                    'wikidata_qid': qid
                }

                if wikipedia:  # Only add wikipedia field if it's not empty
                    record['wikipedia'] = wikipedia

                records.append(record)

            except Exception as e:
                # 尝试获取部分信息以便调试
                person_info = binding.get('person', {}).get('value', 'unknown')
                qid_info = person_info.split('/')[-1] if person_info != 'unknown' else 'unknown'
                cbdb_info = binding.get('cbdb_id', {}).get('value', 'unknown')
                print(f"Error parsing result for entity {qid_info} (CBDB ID: {cbdb_info}): {e}")
                continue

        return records

    def generate_output(self, records: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Generate the final JSON output structure."""
        return {
            'generated_at': datetime.now(timezone.utc).isoformat(),
            'source': 'Wikidata SPARQL',
            'schema_version': 1,
            'records': records
        }

    def fetch_and_save(self, output_file: str = 'wikidata_cbdb_persons.json') -> bool:
        """Main method to fetch data and save to JSON file."""
        print("Fetching CBDB person data from Wikidata...")

        # Execute SPARQL query
        query = self.get_sparql_query()
        sparql_results = self.execute_query(query)

        if not sparql_results:
            print("Failed to fetch data from Wikidata.")
            return False

        # Parse results
        records = self.parse_results(sparql_results)
        print(f"Parsed {len(records)} person records.")

        # Generate output
        output_data = self.generate_output(records)

        # Save to file
        try:
            with open(output_file, 'w', encoding='utf-8') as f:
                json.dump(output_data, f, ensure_ascii=False, indent=2)

            print(f"Data successfully saved to {output_file}")
            print(f"Total records: {len(records)}")

            # Print some statistics
            zh_wiki_count = sum(1 for r in records if r.get('wikipedia', {}).get('zh'))
            en_wiki_count = sum(1 for r in records if r.get('wikipedia', {}).get('en'))
            ja_wiki_count = sum(1 for r in records if r.get('wikipedia', {}).get('ja'))
            print(f"Records with Chinese Wikipedia: {zh_wiki_count}")
            print(f"Records with English Wikipedia: {en_wiki_count}")
            print(f"Records with Japanese Wikipedia: {ja_wiki_count}")

            return True

        except Exception as e:
            print(f"Error saving file: {e}")
            return False


def main():
    """Main function."""
    import argparse

    parser = argparse.ArgumentParser(
        description='Fetch CBDB person data from Wikidata and save as JSON'
    )
    parser.add_argument(
        '-o', '--output',
        default='wikidata_cbdb_persons.json',
        help='Output JSON file name (default: wikidata_cbdb_persons.json)'
    )
    parser.add_argument(
        '--verbose', '-v',
        action='store_true',
        help='Enable verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        print("Verbose mode enabled")

    fetcher = WikidataCBDBFetcher()

    try:
        success = fetcher.fetch_and_save(args.output)
        sys.exit(0 if success else 1)
    except KeyboardInterrupt:
        print("\nOperation cancelled by user.")
        sys.exit(1)
    except Exception as e:
        print(f"Unexpected error: {e}")
        sys.exit(1)


if __name__ == '__main__':
    main()