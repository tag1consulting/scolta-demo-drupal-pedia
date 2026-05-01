# The Athenaeum

A Drupal 11 encyclopedia site built from Wikipedia's ~6,000 Featured Articles — the highest-quality entries in the English Wikipedia. Demonstrates **Scolta** cross-domain semantic search by Tag1 Consulting.

## Quick Start

```bash
git clone https://github.com/tag1consulting/scolta-demo-drupal-pedia.git the-athenaeum
cd the-athenaeum
ddev start
```

`ddev start` will automatically:
1. Download the database dump (~586 MB, one-time, from GitHub Releases)
2. Run `composer install`
3. Import the database
4. Build the Scolta search index
5. Clear Drupal caches

Then visit: **https://the-athenaeum.ddev.site**

Admin: https://the-athenaeum.ddev.site/user — username: `admin`, password: `admin`

## What This Is

**The Athenaeum** contains ~6,000 Wikipedia Featured Articles spanning every domain of human knowledge — from particle physics to medieval battles, from endangered species to architectural landmarks. It demonstrates Scolta's ability to find semantically related content across completely different domains.

### Showcase Queries

Try these in the search bar to see Scolta's cross-domain discovery:

| Query | What Scolta finds |
|-------|-------------------|
| "ancient water systems" | Roman aqueducts, Mesopotamian irrigation, Hawaiian fishponds, qanats |
| "survival in extreme conditions" | Antarctic exploration, extremophile bacteria, Apollo 13, Andes flight disaster |
| "beautiful mathematics" | Fractals, golden ratio, Euler's identity, Islamic geometric patterns |
| "animals that build" | Beavers, termite mounds, bowerbirds, coral reefs, weaver ants |
| "forgotten kingdoms" | Khmer Empire, Aksum, Majapahit, Great Zimbabwe, Nabataeans |
| "when science was wrong" | Phlogiston, luminiferous aether, miasma theory, Piltdown Man |

## Content Import (Session 2+)

Content is imported programmatically from the Wikipedia API. After cloning:

```bash
# Fetch the list of all Featured Articles (~6,000 titles)
ddev drush athenaeum:fetch-list

# Import article content (takes ~6 hours with rate limiting)
ddev drush athenaeum:import-articles

# Map Wikipedia categories to Drupal taxonomy
ddev drush athenaeum:import-categories

# Download lead images from Wikimedia Commons
ddev drush athenaeum:import-images

# Build entity references from "See also" sections
ddev drush athenaeum:cross-reference

# Generate Topic Landing Pages
ddev drush athenaeum:generate-landing-pages

# Optional: AI enrichment (requires ANTHROPIC_API_KEY)
ANTHROPIC_API_KEY=your-key ddev drush athenaeum:enrich

# Build Scolta search index
ddev drush search-api:index
ddev drush scolta:build

# Export database
ddev export-db --gzip --file=db/dump.sql.gz
```

## Architecture

- **CMS:** Drupal 11
- **Search:** Scolta by Tag1 Consulting (semantic search + query expansion + re-ranking)
- **Theme:** Custom `athenaeum` Twig theme, library aesthetic
- **Dev:** DDEV + MariaDB 10.11 + PHP 8.3
- **Content Types:** Featured Article (6,000 nodes), Topic Landing Page (15 nodes)
- **Taxonomies:** Topics (hierarchical, 3 levels), Era (6 terms), Region (9 terms)

## License

- **Content:** CC BY-SA 4.0 (from Wikipedia) — see SOURCES.md
- **Code:** GPL-2.0-or-later (Drupal ecosystem standard)
- **Scolta:** Proprietary (Tag1 Consulting)
