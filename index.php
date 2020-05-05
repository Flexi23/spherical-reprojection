<?php
$enableSaveConf = true;

$absPath = __DIR__; // absolute
function rel($path) {return substr($path,strlen(__DIR__)+1);} // relative

if(isset($_REQUEST['path']) && $_REQUEST['path'] != "") {
	$absPath .= DIRECTORY_SEPARATOR . $_REQUEST['path'];
}

$relPath = rel($absPath);

function saveConf($configuration) {
	global $enableSaveConf;
	if(!$enableSaveConf) {
		echo 'save revoked';
		return;
	}
	$name = basename($configuration->imgSrc);
	$newConfJson = json_encode($configuration);
	$newLine = $name . ' => ' . $newConfJson;
	global $absPath;
	$imgConfsPath = $absPath . DIRECTORY_SEPARATOR . 'tiltConfs.txt';
	if(is_file($imgConfsPath)) {
		$lines = file($imgConfsPath, FILE_IGNORE_NEW_LINES);
		$numEntries = count($lines);
		if($numEntries == 0) {
			echo "$imgConfsPath shouldn't be empty. configuration wasn't saved." . PHP_EOL;
			return;
		}
		$newLines = null;
		echo "$imgConfsPath exists ($numEntries confs)" . PHP_EOL;
		$updated = false;
		foreach ($lines as $oldLine) {
			$confDecl = explode(' => ', $oldLine); // decl = "key => value"
			if(count($confDecl) == 2) {
				$confName = $confDecl[0]; // key
				$oldConfJson = $confDecl[1]; // value (not used here anyway)
				if($confName == $name) {
					if($newLines == null) {
						$newLines = [$newLine];
					}else{
						$newLines[] = $newLine;
					}
					echo "existing entry $name updated." . PHP_EOL;
					$updated = true;
				}else{
					$newLines[] = $oldLine;
				}
			}
		}
		if(!$updated) {
			$newLines[] = $newLine;
			echo "entry $name added." . PHP_EOL;
		}
		file_put_contents($imgConfsPath, implode(PHP_EOL, $newLines)); // write new
	}else{
		file_put_contents($imgConfsPath, $newLine); // create new
		echo "$imgConfsPath created. ($name)" . PHP_EOL;
	}
}

if(isset($_REQUEST['conf'])) { // update per post
	$confJson = $_REQUEST['conf'];
	$conf = json_decode($confJson); // [path, conf]
	if($conf) {
		echo 'parsed' . PHP_EOL;
		global $absPath;
		$absPath .= DIRECTORY_SEPARATOR . $conf[0];
		saveConf($conf[1]);
	}else{
		echo "not parsed: $confJson";
	}
	return;
}

if(isset($_REQUEST['list'])) {
	function toA($dir) {
		$name = rel($dir);
		$url = urlencode(str_replace(DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR, $name));
		$html = "<a href='index.php?list&path=$url'>$name</a>";
		return $html;
	}
	$dirs = glob($absPath . DIRECTORY_SEPARATOR . "*", GLOB_ONLYDIR );
	if(count($dirs) > 0) {
		//$names = array_map("basename", $dirs);
		$links = array_map("toA", $dirs);
		echo "directories:<ul>";
		if( $relPath != ""){
			$relPathArray = explode(DIRECTORY_SEPARATOR, $relPath);
			array_pop($relPathArray);	// minus the last one, this is now the parent path array
			$parentPath = implode(DIRECTORY_SEPARATOR, $relPathArray);
			echo "<li><a href='index.php?list&path=$parentPath'>..</a></li>";
		}
		foreach($links as $link) {
			echo "<li>" . $link . "</li>";
		}
		echo "</ul>";
		return;
	}
}

$imgConfsPath = $absPath . DIRECTORY_SEPARATOR . 'tiltConfs.txt';
$imgConfs = []; // associative array: imageName => confJson
if(file_exists($imgConfsPath)) {
	$lines = file($imgConfsPath, FILE_IGNORE_NEW_LINES);
	foreach ($lines as $line) {	// strings: "key => value"
		$confDecl = explode(' => ', $line);
		if(count($confDecl) == 2) {
			$imageName = $confDecl[0]; // key
			$confJson = $confDecl[1]; // value
			$imgConfs[$imageName] = $confJson;
		}
	}
}

?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">
	<title>Spherical Reprojection</title>
	<script type="text/javascript" src="dat.gui.min.js"></script>
	<script id="shader-vs" type="x-shader/x-vertex">
	attribute vec3 aPos;
	attribute vec2 aTexCoord;
	varying vec2 uv;
	void main(void) {
	gl_Position = vec4(aPos, 1.);
		uv = aTexCoord;
	}
	</script>

	<script id="shader-fs-pano" type="x-shader/x-fragment">
	#ifdef GL_ES
	precision highp float;
	#endif

	varying vec2 uv;

	uniform sampler2D samplerPano;
	uniform sampler2D samplerPanoNearest;
	uniform vec2 aspect;
	uniform vec2 texSize;
	uniform vec2 pixelSize;

	uniform vec3 angles;

	uniform vec2 pointer;
	uniform float magnifierRadius;
	uniform float refractivity;
	uniform float interpolate;

	uniform float mirrorSize;
	uniform vec2 rotation;
	uniform float zoom;
	uniform vec4 scaramuzza;
	uniform float mask;

	uniform float flip;
	uniform float equirect;
	uniform float mercator;
	uniform float azimuthal;
	uniform float stereographic;
	uniform float azimuthalCollage;

	uniform float d;

	uniform float showGrid;

	float border(vec2 domain, vec2 thickness) {
		vec2 uv = fract(domain-vec2(0.5));
		uv = min(uv,1.-uv)*2.;
		return max(
			clamp(uv.x-1.+thickness.x,0.,1.)/(thickness.x),
			clamp(uv.y-1.+thickness.y,0.,1.)/(thickness.y)
		);
	}

	uniform float gamma;
	uniform float brightness;

	vec2 factorA, factorB, product;

	#define pi 3.141592653589793238462643383279
	#define pi_inv 0.318309886183790671537767526745
	#define pi2_inv 0.159154943091895335768883763372

	float atan2(float y, float x) {
		if(x>0.) return atan(y/x);
		if(y>=0. && x<0.) return atan(y/x) + pi;
		if(y<0. && x<0.) return atan(y/x) - pi;
		if(y>0. && x==0.) return pi/2.;
		if(y<0. && x==0.) return -pi/2.;
		if(y==0. && x==0.) return pi/2.; // undefined usually
		return pi/2.;
	}

	vec2 applyMirror(vec2 uv) {
			uv.y = 1.- uv.y; // flipud
			uv.y = mix( uv.y / (1. - mirrorSize), (1.-uv.y) / mirrorSize, float(uv.y > 1.- mirrorSize));
			uv.y = 1.- uv.y; // flipud
			return uv;
	}

	//Licence: spherical reprojection shader code kindly provided by https://www.shadertoy.com/view/4tjGW1
	float FOVX = 360.; //Max 360 deg
	float FOVY = 180.; //Max 180 deg

	const float PI = 3.1415926;

	mat3 rotX(float theta) {
		float s = sin(theta);
		float c = cos(theta);

		mat3 m =
			mat3( 1, 0,  0,
				0, c,  -s,
				0, s,  c);
		return m;
	}

	mat3 rotY(float theta) {
		float s = sin(theta);
		float c = cos(theta);

		mat3 m =
			mat3( c, 0, -s,
				0, 1,  0,
				s, 0,  c);
		return m;
	}

	mat3 rotZ(float theta) {
		float s = sin(theta);
		float c = cos(theta);

		mat3 m =
			mat3( c, -s, 0,
				s, c,  0,
				0, 0,  1);
		return m;
	}

	float deg2rad(float deg) {
		return deg * PI / 180.;
	}

	vec2 tiltEquirect(vec2 uv) {
		vec3 angles = angles;
		if(equirect < 1.){
			angles.z += pi * .5;
		}

		float fovX = deg2rad(FOVX);
		float fovY = deg2rad(FOVY);
		float hAngle = uv.x * fovX;
		float vAngle = uv.y * fovY;

		vec3 p;	// point on the sphere
		p.x = sin(vAngle) * sin(hAngle);
		p.y = cos(vAngle);
		p.z = sin(vAngle) * cos(hAngle);

		// rotate sphere
		p =  rotZ(angles.y) * rotY(angles.x) * rotX(angles.z) * p;

		uv = vec2(atan2(p.x, p.z), acos(p.y)) / vec2(fovX, fovY);

		return uv;
	}

	float logn(float v, float base){
		return log(v)/log(base);
	}

	vec2 uv_polar(vec2 uv, vec2 center){
		vec2 c = uv - center;
		float rad = length(c);
		float ang = atan2(c.x,c.y);
		return vec2(ang, rad);
	}

	vec2 uv_lens_half_sphere(vec2 uv, vec2 position, float radius, float refractivity){
		vec2 polar = uv_polar(uv, position);
		float cone = clamp(1.-polar.y/radius, 0., 1.);
		float halfsphere = sqrt(1.-pow(cone-1.,2.));
		float w = atan2(1.-cone, halfsphere);
		float refrac_w = w-asin(sin(w)/refractivity);
		float refrac_d = 1.-cone - sin(refrac_w)*halfsphere/cos(refrac_w);
		vec2 refrac_uv = position + vec2(sin(polar.x),cos(polar.x))*refrac_d*radius;
		return mix(uv, refrac_uv, float(length(uv-position)<radius));
	}

	void main(void) {
		vec2 uv_orig = uv;
		vec2 uvMagnifyingGlass = uv_lens_half_sphere((uv - 0.5) * aspect, (pointer-0.5)*aspect, magnifierRadius, refractivity);

		vec2 equirectangularUv = (uvMagnifyingGlass+0.5) * vec2(0.5,1) - vec2(0.5/aspect.x - 0.5, 0);
		float equirectangularMask = float(max(equirectangularUv.x,equirectangularUv.y) <= 1. && min(equirectangularUv.x,equirectangularUv.y) >= 0.);

		// azimuthal projection
		vec2 uv = uvMagnifyingGlass;
		float a = -atan2(uv.y, uv.x) - pi*0.5;
		float l = length(uv);
		vec2 azimuthalUv = vec2(a * pi2_inv, l * 2.);
		float azimuthalMask = float(l < 0.5);

		// stereographic projection
		l *= zoom*8.;
		l = scaramuzza.x * l + scaramuzza.y * l * l + scaramuzza.z * l * l * l + scaramuzza.w * l * l * l * l; 
		l *= 1./(scaramuzza.x + scaramuzza.y + scaramuzza.z + scaramuzza.w);
		vec2 stereographicUv = azimuthalUv;
		float dl = d / l;
		float dl2 = dl * dl;
		stereographicUv.y = acos((1. -d + dl * sqrt( dl2 + 2. *d - d*d) ) / (1. + dl2)) * pi_inv;

		stereographicUv = clamp( stereographicUv, -1., 1.);
		float stereographicMask = float(1.);

		// collage of two overlapping azimuthal projections

		uv = 0.5 + vec2( uv.x*rotation.x - uv.y*rotation.y, uv.x*rotation.y + uv.y*rotation.x) * zoom;

		// polar coordinates for the left side
		vec2 c1 = vec2(0.25 - mirrorSize*0.25, 0.5);
		float a1 = -atan2(uv.y - c1.y, uv.x - c1.x) - pi*0.5; // angle
		float d1 = distance(uv, c1) / 0.5; // dist
		float m1 = float(d1 < 1.); // mask
		vec2 leftAzimuthalUv = applyMirror(vec2(a1*pi2_inv, d1));
		float mm = float(d1 < mirrorSize); // mirror mask
		leftAzimuthalUv = mix(leftAzimuthalUv, 1.-applyMirror(1.-leftAzimuthalUv), mm);

		// polar coordinates for the right side
		vec2 c2 = vec2(0.75 + mirrorSize*0.25, 0.5);
		float a2 = atan2(uv.y - c2.y, uv.x - c2.x)+ pi*0.5; // angle
		float d2 = distance(uv, c2) / 0.5; // dist
		float m2 = float(d2 < 1.); // mask
		vec2 rightAzimuthalUv = applyMirror(vec2(a2*pi2_inv, d2)); rightAzimuthalUv.y = 1.-rightAzimuthalUv.y;
		mm = float(d2 < mirrorSize); // mirror mask
		rightAzimuthalUv = mix(rightAzimuthalUv, applyMirror(rightAzimuthalUv), mm);
		float mh = float(0.5 + (uv.y-0.5) * flip < 0.5); // mask horizontal half

		vec2 azimuthalCollageUv = mix(leftAzimuthalUv, rightAzimuthalUv, m2);
		azimuthalCollageUv = mix(azimuthalCollageUv, leftAzimuthalUv, mh * m1);
		float azimuthalCollageMask = max(m1, m2);

		float mixMask = equirectangularMask;
		mixMask = mix(mixMask, azimuthalMask, azimuthal);
		mixMask = mix(mixMask, stereographicMask, stereographic);
		mixMask = mix(mixMask, azimuthalCollageMask, azimuthalCollage);

		vec2 mixUv = equirectangularUv;
		mixUv = mix(mixUv, azimuthalUv, azimuthal);
		mixUv = mix(mixUv, stereographicUv, stereographic);
		mixUv = mix(mixUv, azimuthalCollageUv, azimuthalCollage);

		mixUv = tiltEquirect(mixUv); // spherical reprojection

		//gl_FragColor = texture2D(samplerPano, mixUv); // single texture lookup
		gl_FragColor = mix(texture2D(samplerPanoNearest, mixUv), texture2D(samplerPano, mixUv), interpolate);
		gl_FragColor *= max(1.-mask, mixMask);

		gl_FragColor = pow(gl_FragColor, vec4(1./gamma)) + brightness;

		vec2 uv_grid =  fract((equirectangularUv - 0.5) * vec2(2,1) * 32.);
		vec2 uv_cross = fract((equirectangularUv - 0.5) * vec2(2,1) * 2.);

		float grid = border(uv_grid, pixelSize*64.*aspect); // todo: make width a uniform variable?
		float cross = border(uv_cross, pixelSize*8.*aspect);

		gl_FragColor = mix(gl_FragColor, 1.-gl_FragColor, - max(grid, cross) * showGrid);

		gl_FragColor.a = 1.;
	}
	</script>

	<script type="text/javascript">
		function getShader(gl, id) {
			var shaderScript = document.getElementById(id);
			var str = "";
			var k = shaderScript.firstChild;
			while (k) {
				if (k.nodeType == 3)
					str += k.textContent;
				k = k.nextSibling;
			}
			var shader;
			if (shaderScript.type == "x-shader/x-fragment") {
				shader = gl.createShader(gl.FRAGMENT_SHADER);
			}
			else if (shaderScript.type == "x-shader/x-vertex") {
				shader = gl.createShader(gl.VERTEX_SHADER);
			}
			else {
				return null;
			}
			gl.shaderSource(shader, str);
			gl.compileShader(shader);
			if (gl.getShaderParameter(shader, gl.COMPILE_STATUS) == 0) {
				alert("error compiling shader '" + id + "'\n\n" + gl.getShaderInfoLog(shader));
			}
			return shader;
		}

		var gl;
		var fbos = [];
		let getFBO = N => {
			if(fbos[N] == undefined) {
				fbos[N] = gl.createFramebuffer();
			}
			return fbos[N];
		};

		var frame = 0; // frame counter to be resetted every 1000ms
		var framecount = 0; // not resetted
		var aspect = {x: 1, y: 1};

		var fps, fpsDisplayUpdateTimer;
		var time, starttime = new Date().getTime();

		var pointerX = 0.5;
		var pointerY = 0.5;

		var keyCode2downTimeMap = [];

		// geometry
		var squareBuffer;

		var rerender = true;
		var prog_pano;

		function load() {
			clearInterval(fpsDisplayUpdateTimer);
			var c = document.getElementById("c");
			try {
				gl = c.getContext("webgl2", {
					preserveDrawingBuffer: true
				});
			} catch (e) {
				console.error(e);
			}

			if (!gl) {
				alert("Meh! Y u no support WebGL 2 !?!");
				return;
			}

			document.onmousemove = e => {
				pointerX = e.pageX / innerWidth;
				pointerY = 1 - e.pageY / innerHeight;
				rerender = true;
			};

			window.onresize = e => {
				sizeX = c.width = innerWidth;
				sizeY = c.height = innerHeight;
				rerender = true;
			};

			document.body.onkeydown = e => {
				if(["ArrowLeft", "ArrowRight"].indexOf(e.code) > -1){
					e.preventDefault();	// prevent <select> reaction on cursor left and right, unfortunately also disables Alt+left to go back in browser history
				}
				keyCode2downTimeMap[e.code] = keyCode2downTimeMap[e.code] || {age: 0, reads: 0};
			}
			document.body.onkeyup = e => delete keyCode2downTimeMap[e.code];
			setInterval( () => Object.keys(keyCode2downTimeMap).forEach(keyCode =>
				keyCode2downTimeMap[keyCode].age++)
			, 20); // increment every 20ms for 50Hz

			enableImageDrop(document);

			c.onclick = e => {
				loadNext();
			};

			window.onpopstate = e => {
				var imgName = window.location.hash.replace("#", "");
				while(currentItem.img.name != imgName)
				{
					currentItem = currentItem.next;
				}
				transition2(currentItem.img);
			};

			window.onresize();

			prog_pano = createAndLinkProgram("shader-fs-pano");

			triangleStripGeometry = {
				vertices: new Float32Array([-1, -1, 0, 1, -1, 0, -1, 1, 0, 1, 1, 0]),
				texCoords: new Float32Array([0, 0, 1, 0, 0, 1, 1, 1]),
				vertexSize: 3,
				vertexCount: 4,
				type: gl.TRIANGLE_STRIP
			};

			createTexturedGeometryBuffer(triangleStripGeometry);

			squareBuffer = gl.createBuffer();
			gl.bindBuffer(gl.ARRAY_BUFFER, squareBuffer);

			var aPosLoc = gl.getAttribLocation(prog_pano, "aPos");
			var aTexLoc = gl.getAttribLocation(prog_pano, "aTexCoord");

			gl.enableVertexAttribArray(aPosLoc);
			gl.enableVertexAttribArray(aTexLoc);

			var verticesAndTexCoords = new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1, // one square of a quad!
			0, 0, 1, 0, 0, 1, 1, 1] // hello texture, you be full
			);

			gl.bufferData(gl.ARRAY_BUFFER, verticesAndTexCoords, gl.STATIC_DRAW);
			gl.vertexAttribPointer(aPosLoc, 2, gl.FLOAT, gl.FALSE, 8, 0);
			gl.vertexAttribPointer(aTexLoc, 2, gl.FLOAT, gl.FALSE, 8, 32);

			time = new Date().getTime() - starttime;

			gl.blendFunc(gl.SRC_ALPHA, gl.ONE);
			gl.clearColor(0, 0, 0, 1);

			sequentialLoad(imgSrcs);

			// setup gui

			gui = new dat.GUI();
			gui.add(viewSettings, 'album index');
			gui.add(configuration, 'Previous');
			gui.add(configuration, 'Next');
			gui.add(configuration, 'imgSrc');
			gui.add(configuration, 'gamma', 0.1, 5.);
			gui.add(configuration, 'brightness', -0.5, 0.5);
			gui.add(configuration, 'interpolate', 0., 1.);
			gui.add(configuration, 'angle1', -180, 180);
			gui.add(configuration, 'angle2', -180, 180);
			gui.add(configuration, 'angle3', -180, 180);
			gui.add(viewSettings, 'show grid').onChange(() => {	rerender = true;});

			gui.add(configuration, 'method', methods);
			gui.add(configuration, 'zoom', 0.05, 5);
			gui.add(configuration, 'mask');

			var magnifier = gui.addFolder("magnifier");
			magnifier.add(configuration, "magnifier");
			magnifier.add(configuration, "radius", 0., 0.5);
			magnifier.add(configuration, "refractivity", 1., 5.);

			var stereographic = gui.addFolder('stereographic');
			stereographic.add(configuration, 'd', 0.005, 2.);
			stereographic.add(configuration, 'a0', 0.0, 100.);
			stereographic.add(configuration, 'a1', 0.0, 16.);
			stereographic.add(configuration, 'a2', 0.0, 16.);
			stereographic.add(configuration, 'a3', 0.0, 1.0);
			stereographic.add(configuration, 'Reset');

			var azimuthal = gui.addFolder('azimuthal collage');
			azimuthal.add(configuration, 'mirrorSize', 0.001, 1);
			azimuthal.add(configuration, 'rotation', -180, 180);
			azimuthal.add(configuration, 'flip');

			var controllers = gui.__controllers.concat(magnifier.__controllers, azimuthal.__controllers, stereographic.__controllers);
			controllers.map(_ => _.listen().onChange(() => {
				roundAngles();
				sendConf();
				rerender = true;
			}));

			configuration.Save = save;
			gui.add(configuration, 'Save');

			gui.domElement.style.setProperty('opacity', 0.66);

			// clap

			anim();
		}

		function calcAspect(){
			var ar = sizeX / sizeY;
			if(configuration.method == 'equirectangular') {
				if(ar > 2.) { // fit a rectangle with aspect ratio 2:1 into the viewport
					aspect.x = ar;
					aspect.y = 1.;
				}else {
					aspect.x = 2.;
					aspect.y = 2. / ar;
				}
			}else {
				if(ar > 1.) { // fit a square
					aspect.x = ar;
					aspect.y = 1.;
				}else {
					aspect.x = 1.;
					aspect.y = 1. / ar;
				}
			}
		}

		function item(img) {
			this.img = img;
			this.prev = null;
			this.next = null;
		}

		var firstItem = null;
		var currentItem = null;
		var itemCount = 0;

		function createItem(img) {
			var newItem = new item(img);
			if( firstItem == null) {
				newItem.index = 1;
				firstItem = newItem;
				currentItem = newItem;
				newItem.prev = newItem;
				newItem.next = newItem;
			}
			newItem.next = currentItem.next;
			newItem.next.prev = currentItem.next = newItem;
			newItem.prev = currentItem;
			currentItem = newItem;
			itemCount++;
			var tail = newItem;
			while(tail != firstItem){
				tail.index = tail.prev.index + 1;
				tail = tail.next;
			}
			return currentItem;
		}

		function loadPrevious() {
			currentItem = currentItem.prev;
			transition2(currentItem.img);
			return currentItem;
		}

		function loadNext() {
			currentItem = currentItem.next;
			transition2(currentItem.img);
			return currentItem;
		}

		function basename(path) {
			var segments = path.split("/");
			return segments[segments.length - 1];
		}

		function transition2(img) {
			loadConf(img.name);
			createAndBindImageTexture(img, getFBO(1), gl.TEXTURE1, true);
			createAndBindImageTexture(img, getFBO(2), gl.TEXTURE2, false);

			// todo: buffer two writeable textures to really transition
			rerender = true;
		}

		var imgCache = [];
		function createImg(imgSrc, callback, imgName) {
			var name = basename(imgSrc); // is useless with dataURL-type sources (e.g. from dropped images), override with optional argument imgName!
			if( imgName != undefined) {
				name = imgName;
			}
			if(imgCache[name] != undefined){
				callback(imgCache[name]);
				return imgCache[name];
			}
			var img = document.createElement("img");
			img.onload = () => callback(img);
			img.src = imgSrc;
			img.name = name;
			imgCache[name] = img;
			return createItem(img);
		}

<?php
			echo "		var imgSrcs = " . json_encode(array_map("rel", glob($absPath . "/*.{jpg,jpeg,png,gif,JPG,}", GLOB_BRACE))) . "; // php injected from $relPath\\tiltConfs.txt" . PHP_EOL;
			echo "		var imgConfs = [];" . PHP_EOL;
			foreach ($imgConfs as $key => $value) {
				echo "		imgConfs['$key'] = $value;" . PHP_EOL;
			}
?>
		function loadConf(imgName) {
			window.location.hash = imgName;
			if(imgConfs[imgName]) {
				setConfiguration(Object.assign({}, imgConfs[imgName]));
			} else {
				setConfiguration(Object.assign({}, configuration, {imgSrc: imgName}));
			}
			if(currentItem) {
				configuration.imgSrc = currentItem.img.name;
				viewSettings['album index'] = currentItem.index + ' / ' + itemCount;
			}
		}

		function sequentialLoad(imgSrcs) {
			if(imgSrcs.length > 0) {
				var imgSrc = imgSrcs[0];
				createImg(imgSrc, img => {
					transition2(img)
					sequentialLoad(imgSrcs.slice(1)); // recursion
				});
			}
		}

		function sequentialRead(files){
			if(files.length > 0) {
				var file = files[0];
				var reader = new FileReader();
				reader.onloadend = progressEvent => {
					createImg(reader.result, img => {
						if( imgConfs[img.name] == undefined) {
							imgConfs[img.name] = Object.assign({}, configuration); // use values from previous configuration
							imgConfs[img.name].imgSrc = img.name;
						}
						transition2(img);
						sequentialRead(files.slice(1)); // recursion
					}, file.name);
				};
				reader.readAsDataURL(file);
			}
		}

		function enableImageDrop(elem) {
			['dragenter', 'dragover', 'dragleave', 'drop'].map(_ => elem.addEventListener(_, e => {
				e.preventDefault();
				e.stopPropagation();
				// accept files
				if(e.dataTransfer && e.dataTransfer.files) {
					sequentialRead(Object.assign([],e.dataTransfer.files)); // cast FileList object to Array so we can slice
				}
				// accept uris
				if(e.type == "drop"  && e.dataTransfer.files.length == 0){
					var data = e.dataTransfer.items;
					for (var i = 0; i < data.length; i++) {
						if ((data[i].kind == 'string') && (data[i].type.match('^text/uri-list'))) {
							data[i].getAsString(uri => {
								var xhr = new XMLHttpRequest();
								xhr.open("GET", uri);
								xhr.responseType = "blob"; // forcing it
								xhr.onload = () => sequentialRead([xhr.response]);
								xhr.send();
							});
						}
					}
				}
			}, false));
		}

		function createTexturedGeometryBuffer(geometry) {
			geometry.buffer = gl.createBuffer();
			gl.bindBuffer(gl.ARRAY_BUFFER, geometry.buffer);
			geometry.aPosLoc = gl.getAttribLocation(prog_pano, "aPos");
			gl.enableVertexAttribArray(geometry.aPosLoc);
			geometry.aTexLoc = gl.getAttribLocation(prog_pano, "aTexCoord");
			gl.enableVertexAttribArray(geometry.aTexLoc);
			geometry.texCoordOffset = geometry.vertices.byteLength;
			gl.bufferData(gl.ARRAY_BUFFER, geometry.texCoordOffset + geometry.texCoords.byteLength, gl.STATIC_DRAW);
			gl.bufferSubData(gl.ARRAY_BUFFER, 0, geometry.vertices);
			gl.bufferSubData(gl.ARRAY_BUFFER, geometry.texCoordOffset, geometry.texCoords);
			setGeometryVertexAttribPointers(geometry);
		}

		function setGeometryVertexAttribPointers(geometry) {
			gl.vertexAttribPointer(geometry.aPosLoc, geometry.vertexSize, gl.FLOAT, gl.FALSE, 0, 0);
			gl.vertexAttribPointer(geometry.aTexLoc, 2, gl.FLOAT, gl.FALSE, 0, geometry.texCoordOffset);
		}

		function createAndLinkProgram(fsId) {
			var program = gl.createProgram();
			gl.attachShader(program, getShader(gl, "shader-vs"));
			gl.attachShader(program, getShader(gl, fsId));
			gl.linkProgram(program);
			return program;
		}

		function createAndBindImageTexture(img, fbo, activeUnit, interpolate) {
			if(fbo.texture == undefined) {
				fbo.texture = gl.createTexture();
			}
			gl.bindTexture(gl.TEXTURE_2D, fbo.texture);
			gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
			gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img);
			gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
			gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, interpolate ? gl.LINEAR : gl.NEAREST);
			gl.bindFramebuffer(gl.FRAMEBUFFER, fbo);
			gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, fbo.texture, 0);
			gl.activeTexture(activeUnit);
			gl.bindTexture(gl.TEXTURE_2D, fbo.texture);
			fbo.activeUnit = activeUnit;
		}

		function setUniforms(program) {
			gl.uniform2f(gl.getUniformLocation(program, "texSize"), sizeX, sizeY);
			gl.uniform2f(gl.getUniformLocation(program, "pixelSize"), 1. / sizeX, 1. / sizeY);
			gl.uniform2f(gl.getUniformLocation(program, "aspect"), aspect.x, aspect.y);
			gl.uniform1i(gl.getUniformLocation(program, "samplerPano"), 1);
			gl.uniform1i(gl.getUniformLocation(program, "samplerPanoNearest"), 2);
			gl.uniform1f(gl.getUniformLocation(program, "interpolate"), configuration.interpolate);
			gl.uniform1f(gl.getUniformLocation(program, "mirrorSize"), configuration.mirrorSize);
			gl.uniform2f(gl.getUniformLocation(program, "rotation"), Math.cos(configuration.rotation / 180 * Math.PI), Math.sin(configuration.rotation / 180 * Math.PI));
			gl.uniform3f(gl.getUniformLocation(program, "angles"), configuration.angle1 / 180 * Math.PI, configuration.angle2 / 180 * Math.PI, configuration.angle3 / 180 * Math.PI);
			gl.uniform1f(gl.getUniformLocation(program, "zoom"), 1 / configuration.zoom);
			gl.uniform2f(gl.getUniformLocation(program, "pointer"), pointerX, pointerY);
			gl.uniform1f(gl.getUniformLocation(program, "magnifierRadius"), configuration.radius);
			gl.uniform1f(gl.getUniformLocation(program, "refractivity"), configuration.magnifier ? configuration.refractivity : 1);
			
			gl.uniform4f(gl.getUniformLocation(program, "scaramuzza"), configuration.a0, configuration.a1, configuration.a2, configuration.a3);
			gl.uniform1f(gl.getUniformLocation(program, "mask"), configuration.mask ? 1 : 0);
			gl.uniform2f(gl.getUniformLocation(program, "flip"), configuration.flipX ? -1 : 1, configuration.flipY ? -1 : 1);
			gl.uniform1f(gl.getUniformLocation(program, "equirect"), configuration.method == 'equirectangular' ? 1 : 0);
			gl.uniform1f(gl.getUniformLocation(program, "mercator"), configuration.method == 'mercator' ? 1 : 0);
			gl.uniform1f(gl.getUniformLocation(program, "azimuthal"), configuration.method == 'azimuthal' ? 1 : 0);
			gl.uniform1f(gl.getUniformLocation(program, "stereographic"), configuration.method == 'stereographic' ? 1 : 0);
			gl.uniform1f(gl.getUniformLocation(program, "d"), configuration.d);
			gl.uniform1f(gl.getUniformLocation(program, "azimuthalCollage"), configuration.method == 'azimuthal collage' ? 1 : 0);
			gl.uniform1f(gl.getUniformLocation(program, "flip"), configuration.flip ? -1 : 1);
			gl.uniform1f(gl.getUniformLocation(program, "gamma"), configuration.gamma);
			gl.uniform1f(gl.getUniformLocation(program, "brightness"), configuration.brightness);
			gl.uniform1f(gl.getUniformLocation(program, "showGrid"), viewSettings['show grid'] ? 1 : 0);
		}

		function useGeometry(geometry) {
			gl.bindBuffer(gl.ARRAY_BUFFER, geometry.buffer);
			setGeometryVertexAttribPointers(geometry);
		}

		function renderGeometry(geometry, targetFBO) {
			useGeometry(geometry);
			gl.bindFramebuffer(gl.FRAMEBUFFER, targetFBO);
			gl.drawArrays(geometry.type, 0, geometry.vertexCount);
			gl.flush();
		}

		function renderAsTriangleStrip(targetFBO) {
			renderGeometry(triangleStripGeometry, targetFBO);
		}

		function render() {
			if(fbos.length < 1)
				return;
			
			calcAspect();
			gl.viewport(0, 0, sizeX, sizeY);
			gl.useProgram(prog_pano);
			setUniforms(prog_pano);
			fbos.forEach(fbo => {
				gl.activeTexture(fbo.activeUnit);
				gl.bindTexture(gl.TEXTURE_2D, fbo.texture);
			});
			renderAsTriangleStrip(null);
		}

		function anim() {
			requestAnimationFrame(anim);
			handleKeys();
			if(rerender)
				render();
			rerender = false;
		}

		function handleKeys(){ // #keys
			Object.keys(keyCode2downTimeMap).forEach(keyCode => {
				var age = keyCode2downTimeMap[keyCode].age;
				var reads = keyCode2downTimeMap[keyCode].reads;
				var debounceFramesNum = 23; // assume 50 fps?
				var isNewOrDebounced = reads == 0 || age > debounceFramesNum;
				var sqrFactor = Math.pow((age - debounceFramesNum)*1.5 , 2.) / 100;
				var factor = (reads == 0) ? 1 : (age < debounceFramesNum) ? 0 : sqrFactor;
				switch(keyCode) {
					case "ArrowLeft":
						isNewOrDebounced ? loadPrevious() : {};
						break;

					case "ArrowRight":
						isNewOrDebounced ? loadNext() : {};
						break;

					case "ArrowUp":
						(reads == 0) ? prevMethod() : {};
						break;

					case "ArrowDown":
						(reads == 0) ? nextMethod() : {};
						break;

					case "KeyA":
					case "Numpad4":
						step('angle2', -0.1 * factor);
						break;

					case "KeyD":
					case "Numpad6":
						step('angle2', +0.1 * factor);
						break;

					case "KeyW":
					case "Numpad8":
						step('angle3', -0.1 * factor);
						break;

					case "KeyS":
					case "Numpad5":
						step('angle3', +0.1 * factor);
						break;

					case "KeyQ":
					case "Numpad7":
						step('angle1', -0.1 * factor);
						break;

					case "KeyE":
					case "Numpad9":
						step('angle1', +0.1 * factor);
						break;

					case "KeyX":
					case "Numpad2":
						if(reads == 0){
							viewSettings['show grid'] = !viewSettings['show grid'];
							rerender = true;
						}
						break;

					case "KeyP":
						isNewOrDebounced ? save() : {};
						break;
					case "KeyM":
						if(reads == 0)
						{
							configuration.magnifier = !configuration.magnifier;
							sendConf();
							rerender = true;
						}
						break;
					case "Space":
						(reads == 0) ? shutTheBox() : {};
				}
				keyCode2downTimeMap[keyCode].reads++;
			});
		}

		function save() {
			var sizeXbefore = sizeX;
			var sizeYbefore = sizeY;
			var showGrid = viewSettings['show grid'];
			if(configuration.method == 'azimuthal'){
				c.width = sizeX = 4096;
				c.height = sizeY = 4096;
			}else{
				c.width = sizeX = 4096;
				c.height = sizeY = 2048;
			}
			viewSettings['show grid'] = false;
			render();
			c.toBlob(blob => {
				var elem = window.document.createElement('a');
				elem.href = window.URL.createObjectURL(blob);
				elem.download = currentItem.img.name;
				document.body.appendChild(elem);
				elem.click();
				document.body.removeChild(elem);
				c.width = sizeX = sizeXbefore;
				c.height = sizeY = sizeYbefore;
				viewSettings['show grid'] = showGrid;
				render();
			}, "image/jpeg", 0.95);
		}

		function resetStereographicDistortion(){
			configuration.d = 2; // d == 2: stereographic, 1: gnomonic, 2.4: Twilight (Clark), 2.5: James @see: https://upload.wikimedia.org/wikipedia/commons/b/ba/Comparison_azimuthal_projections.svg
			configuration.zoom = 1;
			configuration.a0 = 1;
			configuration.a1 = 0;
			configuration.a2 = 0;
			configuration.a3 = 0;
		}

		var Configuration = function () {
			this.imgSrc = '';
			this.gamma = 1.;
			this.brightness = 0.000;

			this.interpolate = 0;

			this.radius = 0.2;
			this.magnifier = true;
			this.refractivity = 2.4;

			this.method = 'equirectangular';
			// spherical reprojection Euler angles
			this.angle1 = 0;
			this.angle2 = 0;
			this.angle3 = 0;
			// default method: 'equirectangular'
			// method = 'mercator'
			// ???
			// method = 'stereographic'
			this.d = 2; // d == 2: stereographic, 1: gnomonic, 2.4: Twilight (Clark), 2.5: James @see: https://upload.wikimedia.org/wikipedia/commons/b/ba/Comparison_azimuthal_projections.svg
			// method = 'azimuthal collage'
			this.zoom = 1;
			// @see: https://sites.google.com/site/scarabotix/ocamcalib-toolbox
			// polynomial radial distortion
			this.a0 = 20;
			this.a1 = 0;
			this.a2 = 0;
			this.a3 = 0;
			this.mask = true;
			this.mirrorSize = 0.001;
			this.rotation = -0;
			this.flip = false;
			this.Reset = resetStereographicDistortion;
			this.Save = save;
			this.Next = loadNext;
			this.Previous = loadPrevious;
		};

		var configuration = new Configuration();
		var methods = ['equirectangular', /*'mercator',*/ 'stereographic', 'azimuthal', 'azimuthal collage'];

		var viewSettings = {
			'show grid' : false,
			'album index'  : ' / '
		};

		var gui;

		function setConfiguration(newConf){
			configuration = Object.assign(configuration, newConf);
		}

		function prevMethod(){
			var currentIndex = methods.indexOf(configuration.method);
			if (currentIndex == 0){
				configuration.method = methods[methods.length - 1];
			}else{
				configuration.method = methods[currentIndex - 1];
			}
			sendConf();
			rerender = true;
		}

		function nextMethod(){
			var currentIndex = methods.indexOf(configuration.method);
			if (currentIndex == methods.length - 1){
				configuration.method = methods[0];
			}else{
				configuration.method = methods[currentIndex + 1];
			}
			sendConf();
			rerender = true;
		}

		function step(angle, amount){
			configuration[angle] += amount;
			while(configuration[angle] > 180) {
				configuration[angle] -= 360;
			}
			while(configuration[angle] < -180) {
				configuration[angle] += 360;
			}
			roundAngles();
			sendConf();
			rerender = true;
		}

		function roundAngles() {
			configuration.angle1 = Math.round(configuration.angle1 * 10) / 10;
			configuration.angle2 = Math.round(configuration.angle2 * 10) / 10;
			configuration.angle3 = Math.round(configuration.angle3 * 10) / 10;
		}

		var releaseAfter = 500; // ms
		var sendConfTimeout;
		function sendConf() {
			if(sendConfTimeout) {
				clearTimeout(sendConfTimeout); // revoke old release candidate
			}
			sendConfTimeout = setTimeout( () => {
				imgConfs[configuration.imgSrc] = Object.assign({}, configuration); // save value copy
				<?php if(!$enableSaveConf){
					?>return; // 🤫
				<?php }
				?>var xhr = new XMLHttpRequest();
				xhr.open('POST', 'index.php', true);
				xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
				xhr.onreadystatechange = function () {
					if (xhr.readyState == 4) {
						console.log("xhr response: " + xhr.responseText);
					}
				};
				var formData = 'conf=[<?=json_encode($relPath);?>, ' + JSON.stringify(configuration) + ']';
				xhr.send(formData);
			}, releaseAfter); // hold to release after waiting time for other config updates
		}

		var boxIsShut = false;
		function shutTheBox(){
			boxIsShut = !boxIsShut;
			splainer.style.zIndex = boxIsShut ? -1 : 2;
		}

	</script>
	<style type="text/css">
		body{
			background-color: #000000;
			color: #ffffff;
			overflow: hidden;
		}
		#c {
			position: absolute;
			top: 0;
			left: 0;
		}
		a {
			color: #ffffff;
			font-weight: bold;
		}
		#splainer {
			position: absolute;
			padding: 8px;
			top: 16px;
			left: 16px;
			z-index: 2;
			background-color: rgba(0, 0, 0, 0.2);
			width: 1024px;
		}
	</style>
</head>
<body onload="load()">
	<div id="splainer">
		This is a <a href="https://workshop.chromeexperiments.com/examples/gui" target="dat.gui">dat.gui</a> controlled WebGL 2 tool for spherical reprojection of equirectangular images.<br>
		I thought it would be fun to hang a Ricoh Theta V under a kite, but I got nausea from the perspectives in a VR headset.<br>
		I needed a tool to bring the photographed horizon to the texture's equator and <a href="https://www.shadertoy.com/view/4tjGW1" target="st">Simple 360 degree fov</a> was there to help.<br>
		The <a href="https://en.wikipedia.org/wiki/Euler_angles" target="wiki">Euler angle</a> navigation is prone to <a href="https://en.wikipedia.org/wiki/Gimbal_lock" target="wiki">Gimbal lock</a>. <a href="https://twitter.com/3blue1brown/status/1037717615681069056" target="twitter">Quaternions</a> promise hope, but that's not yet implemented.<br>
		Perhaps <a href="https://threejs.org/docs/#examples/controls/OrbitControls">Orbit Controls</a> could be worth the challenge too. <a href="https://github.com/Flexi23/spherical-reprojection" target="github">Your chance</a>.<br>
		Nikolas Stausbøl from <a href="https://twitter.com/Flexi23/status/1029106003747500032" target="twitter">@evryone_XR</a>, creator of <a href="https://evry.one/nickeldome/">Nickeldome</a>, suggested a way to add exif data for sharing on Facebook.</br>
		I typically use this Windows command line <a href="http://www.mediafire.com/file/jd42i7a8dbhfpaz/exiftool.zip/file">tool</a> with a batch file where you can drop image files on.<br>
		Drop your own <a href="https://en.wikipedia.org/wiki/Equirectangular_projection" target="wiki">equirectangular</a> photos on this site and save reprojections of it, currently at a fixed size of 4096x2048 pixels.<br>
		Press X to toggle a grid overlay on and off.<br>
		Press Q or E to +/- increment angle1, keys A and D control angle2, W and S angle3.<br>
		Increment steps start with 0.1° accuracy but get wider the longer you keep the keys pressed.<br>
		Cursor arrow keys Left and Right switch between images.<br>
		The Up and Down cursor arrow keys change the projection method.<br>
		Chronologically, after equirectangular came the <a href="https://en.wikipedia.org/wiki/Map_projection#Azimuthal_.28projections_onto_a_plane.29" target="wiki">azimuthal projection</a> collage.<br>
		Then came the <a href="https://en.wikipedia.org/wiki/Stereographic_projection" target="wiki">stereographic projection</a>. Both got a few more dat.gui controlled parameters.<br>
		d=0 is very near, d=1 <a href="https://en.wikipedia.org/wiki/Gnomonic_projection" target="wiki">gnomonic projection</a> is a special case in the very center of the sphere, only d=2 is stereographic actually.<br>
		d > 2 is out of the sphere on the other side again. Like <a href="https://upload.wikimedia.org/wikipedia/commons/b/ba/Comparison_azimuthal_projections.svg" target="wiki">here</a> but with a changed sign.<br>
		Press F11 for fullscreen and the space bar to shut this splainer box.
	</div>
	<canvas id="c"/>
</body>
</html>
