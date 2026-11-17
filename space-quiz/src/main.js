import * as THREE from "three";

import { MainScene }
from "./scenes/MainScene";

const scene =
    new THREE.Scene();

const camera = new THREE.PerspectiveCamera(
    60,
    window.innerWidth / window.innerHeight,
    0.1,
    1000
);
// 將 Y 軸拉高（例如從 5 改為 8），Z 軸拉遠（例如從 20 改為 25），俯視整個星系
camera.position.set(0, 8, 25);

const renderer =
    new THREE.WebGLRenderer({

        antialias: true

    });

renderer.setSize(

    window.innerWidth,

    window.innerHeight

);

const appContainer = document.getElementById('app');
appContainer.appendChild(renderer.domElement);

const mainScene =
    new MainScene(
        scene,
        camera
    );

mainScene.init();

window.addEventListener(
    "keydown",
    (event) => {

        if (event.key === "ArrowRight")
            mainScene.nextPlanet();

        if (event.key === "ArrowLeft")
            mainScene.previousPlanet();
    }
);

function animate() {

    requestAnimationFrame(
        animate
    );

    mainScene.update();

    renderer.render(
        scene,
        camera
    );
}

animate();
// 監聽視窗大小改變，自動修正長寬比，解決星球變橢圓形的問題
window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});
