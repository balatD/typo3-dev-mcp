ENV EXTENSION_KEY=t3boost

# Landing page listing the per-version environments
RUN rm -f /var/www/html/index.html
RUN cat > /var/www/html/index.html << 'EOF'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>EXT:t3boost - DDEV Environment</title>
<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:50px auto;padding:0 20px;line-height:1.6}h1{color:#ff8700}a{color:#0078e7}</style>
</head>
<body>
<h1>EXT:t3boost Development Environment</h1>
<ul>
<li>TYPO3 13.4 LTS: <a href="https://v13.t3boost.ddev.site/">Frontend</a> · <a href="https://v13.t3boost.ddev.site/typo3/">Backend</a></li>
<li>TYPO3 14: <a href="https://v14.t3boost.ddev.site/">Frontend</a> · <a href="https://v14.t3boost.ddev.site/typo3/">Backend</a></li>
</ul>
<p>Credentials: <code>admin</code> / <code>Joh316!!</code></p>
<p>Install: <code>ddev install-v13</code> · <code>ddev install-v14</code></p>
</body>
</html>
EOF

RUN mkdir -p /var/www/html/v13/public/typo3 /var/www/html/v14/public/typo3
RUN echo "<h1>Perform this first</h1> <code>ddev install-v13</code>" > /var/www/html/v13/public/index.html
RUN echo "<h1>Perform this first</h1> <code>ddev install-v13</code>" > /var/www/html/v13/public/typo3/index.html
RUN echo "<h1>Perform this first</h1> <code>ddev install-v14</code>" > /var/www/html/v14/public/index.html
RUN echo "<h1>Perform this first</h1> <code>ddev install-v14</code>" > /var/www/html/v14/public/typo3/index.html

ARG uid
ARG gid
RUN chown -R $uid:$gid /var/www/html
