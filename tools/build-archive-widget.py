"""Build templates/archive-html-widget.html: the archive CSS (comments
stripped) in a <style> block, then the [exc_archive] shortcode with the
outro, ready to paste into one Elementor HTML widget.

Run from the repo root:  python3 tools/build-archive-widget.py
"""
import re

css = open('snippets/exc-product-archive.css').read()
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
css = '\n'.join(line.rstrip() for line in css.splitlines())
css = re.sub(r'\n{2,}', '\n', css).strip()
assert '</style' not in css

shortcode = open('templates/archive-shortcode-widget.txt').read().strip()
out = '<style id="exc-product-archive-css">\n' + css + '\n</style>\n' + shortcode + '\n'
open('templates/archive-html-widget.html', 'w').write(out)
print('templates/archive-html-widget.html:', len(out), 'bytes')
