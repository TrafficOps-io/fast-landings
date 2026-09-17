"""Export email-safe, opaque PNG marks from the shared SVG sources (requires rsvg-convert)."""

from pathlib import Path
import re
import subprocess

brand_dir = Path(__file__).resolve().parent.parent / 'resources' / 'brand'
output_dir = brand_dir / 'mail'
output_dir.mkdir(exist_ok=True)

for brand in ('trafficops', 'hookroute', 'pwapps'):
    svg = (brand_dir / f'{brand}.svg').read_text()
    svg = svg.replace('currentColor', '#241f1d')
    svg = re.sub(r'var\(--[\w-]+,\s*(#[\da-fA-F]+)\)', r'\1', svg)
    subprocess.run(
        ['rsvg-convert', '--background-color', '#fbf7f2', '--height', '120',
         '--output', str(output_dir / f'{brand}.png')],
        input=svg.encode(), check=True,
    )
