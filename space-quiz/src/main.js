import * as THREE from "three";
import { MainScene } from "./scenes/MainScene";
import { TeacherScene } from "./scenes/TeacherScene";
import { AuthTransition } from "./auth/AuthTransition";
import { AuthModal } from "./auth/AuthModal";

const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(
    60,
    window.innerWidth / window.innerHeight,
    0.1,
    1000
);
camera.position.set(0, 8, 25);

const renderer = new THREE.WebGLRenderer({
    antialias: true
});
renderer.setSize(window.innerWidth, window.innerHeight);

const appContainer = document.getElementById("app");
appContainer.appendChild(renderer.domElement);

const studentScene = new MainScene(scene, camera);
const teacherScene = new TeacherScene(scene, camera);

// 初始僅建立燈光與 3D 星空背景
studentScene.createLights();
studentScene.createStars();

let activeRole = null; // "student" | "teacher" | null
let studentInitialized = false;
let teacherInitialized = false;

function enterUniverse(role) {
    authModal.hide();
    activeRole = role === "teacher" ? "teacher" : "student";

    if (activeRole === "teacher") {
        if (!teacherInitialized) {
            teacherScene.init();
            teacherInitialized = true;
        }
    } else {
        if (!studentInitialized) {
            studentScene.init();
            studentInitialized = true;
        }
    }
}

const authModal = new AuthModal({
    onSuccess: ({ role, mode, data }) => {
        console.log(`${role} ${mode} 成功：`, data);
        enterUniverse(role);
    },
    onBackToRoleSelect: () => {
        authModal.hide();
        authTransition.resetAndShow();
    }
});

const authTransition = new AuthTransition({
    skyImage: "/sky-bg.png",
    studentUfoImage: "/ufo-student.png",
    teacherUfoImage: "/ufo-teacher.png",
    onComplete: (selectedRole) => {
        authModal.mount(selectedRole);
    }
});
authTransition.mount();

window.addEventListener("keydown", (event) => {
    if (activeRole === "teacher") {
        if (event.key === "ArrowRight") teacherScene.nextPlanet();
        if (event.key === "ArrowLeft") teacherScene.previousPlanet();
    } else if (activeRole === "student") {
        if (event.key === "ArrowRight") studentScene.nextPlanet();
        if (event.key === "ArrowLeft") studentScene.previousPlanet();
    }
});

function animate() {
    requestAnimationFrame(animate);

    if (activeRole === "teacher") {
        teacherScene.update();
    } else if (activeRole === "student") {
        studentScene.update();
    } else {
        // 身分選擇與登入階段：僅讓星空背景緩慢自轉
        if (studentScene.starField) {
            studentScene.starField.rotation.y += 0.0002;
        }
    }

    renderer.render(scene, camera);
}
animate();

window.addEventListener("resize", () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});