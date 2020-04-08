# 🪁⛅📷🍳🙃🌐💫🙂🔍

https://cake23.de/spherical-reprojection

This php script scans a directory for all images and renders a single WebGL 2 page.
It also supports drag and drop of image files, which only make sense for real equirectangular 360° images.
You can call it with the parameter ?list to list all subdirectories as links.
The page sends a XHR request to persist the view configuration on every change. The server method just writes a key value pair with the filename => json configuration string to a tiltConf.txt file.
