"""Build the single-HTML-widget files from the CSS snippet:

  templates/archive-html-widget.html  styles + [exc_archive] + outro
  templates/search-html-widget.html   styles + [exc_search]
  templates/latest-html-widget.html   styles + [exc_latest] + outro

Each is the archive CSS (comments stripped) in a <style> block followed
by the shortcode content from the matching *-shortcode-widget.txt file.

Run from the repo root:  python3 tools/build-archive-widget.py
"""
import re

css = open('snippets/exc-product-archive.css').read()
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
css = '\n'.join(line.rstrip() for line in css.splitlines())
css = re.sub(r'\n{2,}', '\n', css).strip()
assert '</style' not in css
style = '<style id="exc-product-archive-css">\n' + css + '\n</style>\n'

for name in ('archive', 'search', 'latest'):
    shortcode = open('templates/%s-shortcode-widget.txt' % name).read().strip()
    out = style + shortcode + '\n'
    path = 'templates/%s-html-widget.html' % name
    open(path, 'w').write(out)
    print(path + ':', len(out), 'bytes')
