import * as THREE from "three";
import { Planet } from "../objects/Planet";
import { Character } from "../objects/Character";
import { slidePlanets } from "../animations/PlanetSlide";
import { flyToPlanet } from "../animations/CameraFly";
import { createFlag, createBook, createPencil } from "../objects/ExamObjects";

export class MainScene {
    constructor(scene, camera) {
        this.scene = scene;
        this.camera = camera;
        this.planets = [];
        this.currentIndex = 0;
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2();
        this.isAnimating = false;
    }

    init() {
        this.createLights();
        this.createStars();
        this.createOrbits();
        this.createPlanets();
        this.createJoinPlanet();
        this.createCharacter();
        
        this.createJoinModalDOM(); 
        this.createStudentInfoModalDOM(); 
        this.createSubjectHUDDOM(); // <--- 新增：建立科目 HUD 面板

        this.bindEvents();
        this.createBackBtnDOM();
    }

    createBackBtnDOM() {
        const btnHTML = `<button id="backGalaxyBtn" class="back-btn">⬅ 返回宇宙總覽</button>`;
        document.body.insertAdjacentHTML('beforeend', btnHTML);

        document.getElementById('backGalaxyBtn').addEventListener('click', () => {
            this.returnToGalaxy();
        });
    }
    
    createLights() {
        const ambient = new THREE.AmbientLight(0xffffff, 2);
        this.scene.add(ambient);
        const directional = new THREE.DirectionalLight(0xffffff, 3);
        directional.position.set(10, 10, 10);
        this.scene.add(directional);
    }

    createOrbits() {
        // 建立只在「左下角」出現的黃色軌道弧線
        const material = new THREE.LineBasicMaterial({ 
            color: 0xffe84a, 
            transparent: true, 
            opacity: .6 
        });
        
        for (let i = 0; i < 5; i++) {
            // 使用 EllipseCurve 建立橢圓軌道，透過調整起訖角度只截取左下角片段
            const curve = new THREE.EllipseCurve(
                0, 3,                 // 圓心位置稍微偏左下
                25 + i * 15, 3 + i * 1,  // 橫向與縱向半徑
                Math.PI * 1.25,          // 起始角度（左下角區域）
                Math.PI * 1.249,          // 結束角度
                false,                   // 順時針
                Math.PI * 0.03           // 旋轉角度
            );

            const points = curve.getPoints(50);
            const geometry = new THREE.BufferGeometry().setFromPoints(
                points.map(p => new THREE.Vector3(p.x, p.y, -15 - i * 5))
            );
            const arcLine = new THREE.Line(geometry, material);
            this.scene.add(arcLine);
        }
    }

    createStars() {
        const geometry = new THREE.BufferGeometry();
        const vertices = [];
        for (let i = 0; i < 3000; i++) {
            vertices.push(
                (Math.random() - 0.5) * 500,
                (Math.random() - 0.5) * 500,
                (Math.random() - 0.5) * 500
            );
        }
        geometry.setAttribute("position", new THREE.Float32BufferAttribute(vertices, 3));
        this.starField = new THREE.Points(
            geometry,
            new THREE.PointsMaterial({ color: 0xffffff, size: 0.5 })
        );
        this.scene.add(this.starField);
    }

    createPlanets() {
        // 定義各科目的詳細資料（未來可對接 API）
        const subjects = [
            { name: "數學", color: 0x89d8ff, texture: "/planet1.jpg", level: "Lv. 2", exams: 3, avgScore: 85 },
            { name: "國文", color: 0xffe84a, texture: "/planet2.jpg", level: "Lv. 1", exams: 2, avgScore: 90 },
            { name: "英文", color: 0x63ff7f, texture: "/planet3.jpg", level: "Lv. 3", exams: 5, avgScore: 78 },
            { name: "自然", color: 0xff8cdc, texture: "/planet4.jpg", level: "Lv. 1", exams: 4, avgScore: 88 }
        ];

        subjects.forEach((subject, index) => {
            const planet = new Planet(subject.name, subject.color, index * 12, 3, subject.texture);
            // 將詳細資料存入 userData，方便後續 HUD 或考試場景讀取
            planet.mesh.userData.details = {
                level: subject.level,
                exams: subject.exams,
                avgScore: subject.avgScore
            };
            this.scene.add(planet.mesh);
            this.planets.push(planet);
        });

        slidePlanets(this.planets, this.currentIndex);
    }

    createJoinPlanet() {
        // 將加入課程星球移到左上角 (x: -30, y: 20, z: -5)
        this.joinPlanet = new Planet("加入課程", 0x888888, 0, 2.0, "/join-planet.jpg");
        this.joinPlanet.mesh.position.set(-20, 15, 0);
        this.scene.add(this.joinPlanet.mesh);
    }

    createCharacter() {
        this.character = new Character();
        this.scene.add(this.character.mesh);
        this.updateCharacter();
    }

    updateCharacter() {
        const currentPlanet = this.planets[this.currentIndex];
        if (currentPlanet) {
            // 1. 直接複製當前星球在 3D 空間中的座標 (X, Y, Z 全部同步)
            this.character.mesh.position.copy(currentPlanet.mesh.position);
            
            // 2. 讓小人站在星球正上方 (星球半徑約為 3，因此 Y 軸加上 3.5 確保剛好踩在球面上)
            this.character.mesh.position.y = currentPlanet.mesh.position.y + 6.5;
        }
    }

    // 進入特定星球的考試視圖
    enterExamScene(planet) {
        this.isExamMode = true;
        this.currentExamPlanet = planet;

        // 隱藏 HUD，顯示返回按鈕
        document.getElementById('subjectHUD').style.display = 'none';
        document.getElementById('backGalaxyBtn').classList.add('active');

        // 在星球上方生成旗幟、書本、鉛筆
        this.examGroup = new THREE.Group();

        const flag = createFlag(planet.name);
        flag.position.set(-1.5, 3.2, 0);
        this.examGroup.add(flag);

        const book = createBook();
        book.position.set(-2.2, 3.0, 1.2);
        this.examGroup.add(book);

        const pencil = createPencil();
        pencil.position.set(1.8, 3.2, 0.5);
        this.examGroup.add(pencil);

        // 將考試物件掛載到該星球的 mesh 上，跟隨星球同步旋轉
        planet.mesh.add(this.examGroup);

        // 執行鏡頭飛入動畫
        flyToPlanet(this.camera, planet.mesh, () => {
            console.log(`已成功進入【${planet.name}】考試場景！`);
        });
    }

    // 返回宇宙總覽
    returnToGalaxy() {
        if (this.isAnimating) return;
        this.isAnimating = true;

        // 1. 移除掛載在星球身上的考試物件群組
        if (this.currentExamPlanet && this.examGroup) {
            this.currentExamPlanet.mesh.remove(this.examGroup);
            this.examGroup = null;
            this.currentExamPlanet = null;
        }

        // 2. 切換 DOM 介面顯示
        document.getElementById('backGalaxyBtn').classList.remove('active');
        document.getElementById('subjectHUD').style.display = 'block';

        // 3. 取得目前中心星球的位置，讓相機順暢退回該星球前方的總覽視角
        const currentPlanet = this.planets[this.currentIndex];
        const targetPos = currentPlanet.mesh.position;

        const returnPosition = {
            x: targetPos.x,
            y: targetPos.y + 8,     // 保持上方俯視的高度
            z: targetPos.z + 25     // 保持適當的拉遠距離
        };

        const returnLookAt = {
            x: targetPos.x,
            y: targetPos.y,
            z: targetPos.z
        };

        gsap.to(this.camera.position, {
            x: returnPosition.x,
            y: returnPosition.y,
            z: returnPosition.z,
            duration: 1.5,
            ease: "power2.inOut",
            onUpdate: () => {
                this.camera.lookAt(returnLookAt.x, returnLookAt.y, returnLookAt.z);
            },
            onComplete: () => {
                this.isAnimating = false;
                this.isExamMode = false;
                console.log("已成功返回宇宙總覽");
            }
        });
    }

    // --- 新增：建立右上角科目 HUD 面板 DOM ---
    createSubjectHUDDOM() {
        const hudHTML = `
            <div id="subjectHUD" class="subject-hud">
                <h2 id="hudTitle">載入中...</h2>
                <p>目前等級：<span id="hudLevel">-</span></p>
                <p>可考測驗：<span id="hudExams">-</span> 份</p>
                <p>平均分數：<span id="hudScore">-</span> 分</p>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', hudHTML);
        this.updateHUD(); // 初始化時更新第一顆星球的資料
    }

    // 更新 HUD 顯示內容
    updateHUD() {
        const currentPlanet = this.planets[this.currentIndex];
        const details = currentPlanet.mesh.userData.details;

        document.getElementById('hudTitle').innerText = `科目：${currentPlanet.name}`;
        document.getElementById('hudLevel').innerText = details.level;
        document.getElementById('hudExams').innerText = details.exams;
        document.getElementById('hudScore').innerText = details.avgScore;
    }

    createJoinModalDOM() {
        const modalHTML = `
            <div id="joinModal" class="modal-overlay">
                <div class="modal-box">
                    <h3>加入新課程</h3>
                    <p style="font-size: 14px; color: #aaa; margin-bottom: 15px;">請輸入老師提供的課程代碼</p>
                    <input type="text" id="courseCodeInput" placeholder="例如：MATH101" />
                    <div class="modal-actions">
                        <button class="btn-cancel" id="closeModalBtn">取消</button>
                        <button class="btn-confirm" id="submitJoinBtn">確認加入</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        document.getElementById('closeModalBtn').addEventListener('click', () => {
            document.getElementById('joinModal').classList.remove('active');
        });

        document.getElementById('submitJoinBtn').addEventListener('click', () => {
            const code = document.getElementById('courseCodeInput').value;
            if (!code) {
                alert('請輸入課程代碼！');
                return;
            }
            alert(`成功送出加入課程代碼：${code}`);
            document.getElementById('joinModal').classList.remove('active');
        });
    }

    createStudentInfoModalDOM() {
        const infoModalHTML = `
            <div id="studentInfoModal" class="modal-overlay">
                <div class="modal-box">
                    <h3>學生個人檔案</h3>
                    <div class="student-info-content">
                        <p>姓名：<span>王小明</span></p>
                        <p>學號：<span>A11111111</span></p>
                        <p>身份：<span>Student (學生)</span></p>
                        <p>已修課程：<span>數學、國文、英文、自然</span></p>
                    </div>
                    <div class="modal-actions">
                        <button class="btn-confirm" id="closeInfoBtn" style="width: 100%;">關閉視窗</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', infoModalHTML);

        document.getElementById('closeInfoBtn').addEventListener('click', () => {
            document.getElementById('studentInfoModal').classList.remove('active');
        });
    }

    bindEvents() {
        window.addEventListener("mousemove", (event) => {
            if (event.target.closest('.modal-overlay.active')) return;

            this.mouse.x = (event.clientX / window.innerWidth) * 2 - 1;
            this.mouse.y = -(event.clientY / window.innerHeight) * 2 + 1;
            this.raycaster.setFromCamera(this.mouse, this.camera);

            const objects = this.planets.map(p => p.mesh);
            objects.push(this.character.mesh);
            objects.push(this.joinPlanet.mesh);

            const intersects = this.raycaster.intersectObjects(objects, true);

            document.body.style.cursor = "default";
            this.planets.forEach(p => p.mesh.material.emissive.setHex(0x000000));
            this.joinPlanet.mesh.material.emissive.setHex(0x000000);
            this.character.mesh.traverse(child => {
                if (child.isMesh && child.material.emissive) {
                    child.material.emissive.setHex(0x000000);
                }
            });

            if (intersects.length > 0) {
                document.body.style.cursor = "pointer";
                const hovered = intersects[0].object;

                if (hovered.userData.type === "planet" || hovered === this.joinPlanet.mesh) {
                    let rootMesh = hovered;
                    while (rootMesh.parent && rootMesh.parent !== this.scene) {
                        if (rootMesh.parent.type === "Mesh") rootMesh = rootMesh.parent;
                        else break;
                    }
                    if (rootMesh.material && rootMesh.material.emissive) {
                        rootMesh.material.emissive.setHex(0x00aaff);
                        rootMesh.material.emissiveIntensity = 0.8;
                    }
                }
            }
        });

        window.addEventListener("click", (event) => {
            // 如果正在播放動畫或點擊到 Modal，直接返回
            if (this.isAnimating) return;
            if (event.target.closest('.modal-box')) return;

            this.mouse.x = (event.clientX / window.innerWidth) * 2 - 1;
            this.mouse.y = -(event.clientY / window.innerHeight) * 2 + 1;
            this.raycaster.setFromCamera(this.mouse, this.camera);

            const objects = this.planets.map(p => p.mesh);
            objects.push(this.character.mesh);
            objects.push(this.joinPlanet.mesh);

            const intersects = this.raycaster.intersectObjects(objects, true);
            if (intersects.length === 0) return;

            const clicked = intersects[0].object;

            if (clicked === this.joinPlanet.mesh || clicked.parent === this.joinPlanet.mesh) {
                this.showJoinModal();
                return;
            }

            if (clicked === this.character.mesh || this.character.mesh.getObjectById(clicked.id)) {
                document.getElementById('studentInfoModal').classList.add('active');
                return;
            }

            if (clicked.userData.type === "planet") {
                if (this.isAnimating || this.isExamMode) return;
                this.isAnimating = true;

                // 找到對應的 Planet 物件
                const targetPlanet = this.planets.find(p => p.mesh === clicked || p.mesh.getObjectById(clicked.id));
                if (targetPlanet) {
                    this.enterExamScene(targetPlanet);
                }
                this.isAnimating = false;
            }
        });
    }

    nextPlanet() {
        this.currentIndex++;
        if (this.currentIndex >= this.planets.length) {
            this.currentIndex = 0;
        }
        slidePlanets(this.planets, this.currentIndex);
        this.updateHUD(); // <--- 切換星球時同步更新 HUD 資訊
        setTimeout(() => {
            this.updateCharacter();
        }, 1000);
    }

    previousPlanet() {
        this.currentIndex--;
        if (this.currentIndex < 0) {
            this.currentIndex = this.planets.length - 1;
        }
        slidePlanets(this.planets, this.currentIndex);
        this.updateHUD(); // <--- 切換星球時同步更新 HUD 資訊
        setTimeout(() => {
            this.updateCharacter();
        }, 1000);
    }

    update() {
        if (this.starField) {
            this.starField.rotation.y += 0.0002;
        }
        this.planets.forEach(planet => {
            planet.mesh.rotation.y += 0.005;
        });
        if (this.joinPlanet) {
            this.joinPlanet.mesh.rotation.y += 0.005;
        this.updateCharacter();
        }
    }
}
