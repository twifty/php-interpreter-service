#!/bin/bash
# Unpack secrets; -C ensures they unpack *in* the .travis directory
tar xvf .travis/secrets.tar -C .travis

# Setup SSH agent:
eval "$(ssh-agent -s)" #start the ssh agent
chmod 600 .travis/build-key.pem
ssh-add .travis/build-key.pem

# Setup git defaults:
git config --global user.email "$COMMIT_AUTHOR_EMAIL"
git config --global user.name "$COMMIT_AUTHOR_USERNAME"

# Add SSH-based remote to GitHub repo:
git remote add deploy git@github.com:twifty/php-interpreter-service.git
git fetch deploy

# Get box and build PHAR
wget https://box-project.github.io/box2/manifest.json
BOX_URL=$(php bin/get-box.php manifest.json)
rm manifest.json
wget -O box.phar ${BOX_URL}
chmod 755 box.phar

# Build the phar, will output into /dist directory
mkdir dist
./box.phar build -vv

# Without the following step, we cannot checkout the gh-pages branch due to
# file conflicts:
mv component-installer.phar component-installer.phar.tmp

# Checkout gh-pages and add PHAR file and version:
git checkout -b gh-pages deploy/gh-pages
mv component-installer.phar.tmp component-installer.phar
sha1sum component-installer.phar > component-installer.phar.version
git add component-installer.phar component-installer.phar.version

# Commit and push:
git commit -m 'Rebuilt phar'
git push deploy gh-pages:gh-pages
