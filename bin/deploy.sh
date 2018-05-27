#!/bin/bash

# Taken from https://mwop.net/blog/2015-12-14-secure-phar-automation.html
# if [ "$TRAVIS_PULL_REQUEST" != "false" -o "$TRAVIS_BRANCH" != "master" ]; then
#     echo "Skipping deploy"
#     exit 0
# fi

# Unpack secrets; -C ensures they unpack *in* the .travis directory
tar xvf .travis/secrets.tar -C .travis

# Setup SSH agent:
eval "$(ssh-agent -s)" #start the ssh agent
chmod 600 .travis/build-key.pem
ssh-add .travis/build-key.pem

# Setup git defaults:
git config --global user.email "$COMMIT_AUTHOR_EMAIL"
git config --global user.name "$COMMIT_AUTHOR_USERNAME"

# Get box and build PHAR
wget https://box-project.github.io/box2/manifest.json
BOX_URL=$(php bin/get-box.php manifest.json)
rm manifest.json
wget -O box.phar ${BOX_URL}
chmod 755 box.phar

# Add SSH-based remote to GitHub repo:
git remote add deploy git@github.com:twifty/php-interpreter-service.git
git fetch deploy

# Build the phar, will output into /dist directory
mkdir dist
./box.phar build

# Without the following step, we cannot checkout the gh-pages branch due to
# file conflicts:
mv dist/php-interpreter.phar dist/php-interpreter.phar.tmp

# Checkout gh-pages and add PHAR file and version:
git checkout gh-pages

oldHash=`md5sum dist/php-interpreter.phar`
mv dist/php-interpreter.phar.tmp dist/php-interpreter.phar
newHash='md5sum dist/php-interpreter.phar'

if [ "x$oldHash" != "x$newHash" ]; then
    echo "No changes to the output on this push; exiting."
    # exit 0
fi

echo "$newhash" > dist/php-interpreter.phar.md5
git add dist/php-interpreter.phar dist/php-interpreter.phar.version dist/php-interpreter.phar.md5

version=`cat dist/php-interpreter.phar.version`
a=( ${version//./ } )
((a[2]++))
version="${a[0]}.${a[1]}.${a[2]}"
echo "$version" > dist/php-interpreter.phar.version

# Commit and push:
git commit -am "Rebuilt phar ${version}"
git push deploy gh-pages:gh-pages
