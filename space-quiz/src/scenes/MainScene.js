import * as THREE from "three";
import gsap from "gsap";
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
        this.createHoverTooltipDOM();
        
        this.createJoinModalDOM(); 
        this.createStudentInfoModalDOM(); 
        this.createSubjectHUDDOM(); // <--- 新增：建立科目 HUD 面板

        this.bindEvents();
        this.createBackBtnDOM();
        this.createQuizModalDOM();
        this.createExamSelectModalDOM();
        this.createResultModalDOM();
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

    // 建立浮動提示字樣 DOM
    createHoverTooltipDOM() {
        const tooltipHTML = `<div id="hoverTooltip" class="hover-tooltip"></div>`;
        document.body.insertAdjacentHTML("beforeend", tooltipHTML);
        this.hoverTooltip = document.getElementById("hoverTooltip");
    }

    // 顯示或隱藏提示字樣並跟隨滑鼠
    updateHoverTooltip(text, x, y) {
        if (!this.hoverTooltip) return;

        if (text) {
            this.hoverTooltip.innerText = text;
            this.hoverTooltip.style.left = `${x}px`;
            this.hoverTooltip.style.top = `${y}px`;
            this.hoverTooltip.classList.add("visible");
        } else {
            this.hoverTooltip.classList.remove("visible");
        }
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

        // 1. 旗幟擺在中間偏右
        const flag = createFlag(planet.name);
        flag.position.set(0.2, 2, -0.5);
        this.examGroup.add(flag);

        // 2. 書本擺在左側，呈現「成績與解答」
        const book = createBook();
        book.position.set(-1.8, 3, 0.8);
        this.examGroup.add(book);

        // 3. 鉛筆擺在右側，筆尖剛好插入星球表面
        const pencil = createPencil();
        pencil.position.set(1.6, 4, 0.5);
        this.examGroup.add(pencil);

        planet.mesh.add(this.examGroup);

        // 將考試物件掛載到該星球的 mesh 上，跟隨星球同步旋轉
        planet.mesh.add(this.examGroup);

        // 執行鏡頭飛入動畫
        flyToPlanet(this.camera, planet.mesh, () => {
            this.isAnimating = false; // <--- 【修改】等飛入動畫完全結束後，才解除鎖定
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

        // 3. 取得目前中心星球的位置作為視線焦點，讓相機退回正上方偏遠的完美總覽視角
        const currentPlanet = this.planets[this.currentIndex];
        const targetPos = currentPlanet.mesh.position;

        // 讓相機回到以「該星球 X 軸為中心，上方高度 8、深度 25」的位置
        const returnPosition = {
            x: targetPos.x,
            y: targetPos.y + 10,   // 保持在星球上方高度 8
            z: targetPos.z + 40   // 拉遠距離
        };
        
        // 讓相機的視線焦點精準看著該星球中心
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
                // 動態更新視線，確保飛行過程中鏡頭都是盯著星球中心
                this.camera.lookAt(returnLookAt.x, returnLookAt.y, returnLookAt.z);
            },
            onComplete: () => {
                // 動畫結束後強制精準對齊，解除鎖定
                this.camera.position.set(returnPosition.x, returnPosition.y, returnPosition.z);
                this.camera.lookAt(returnLookAt.x, returnLookAt.y, returnLookAt.z);
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

    // 在 MainScene 類別中新增這個方法，用來動態產生新星球
    addNewPlanet(courseName) {
        // 1. 隨機挑選 planet1 ~ planet4 紋理
        const textures = [
            "/planet1.jpg",
            "/planet2.jpg",
            "/planet3.jpg",
            "/planet4.jpg"
        ];
        const randomTexture = textures[Math.floor(Math.random() * textures.length)];
        
        // 2. 建立新 Planet 物件（顏色先給預設白色，靠紋理貼圖呈現）
        const newIndex = this.planets.length;
        const newPlanet = new Planet(courseName, 0xffffff, newIndex * 12, 3, randomTexture);
        
        // 3. 設定預設詳細資料
        newPlanet.mesh.userData.details = {
            level: "Lv. 1",
            exams: 1,
            avgScore: 0
        };
        
        // 4. 推入 planets 陣列並加入 scene
        this.scene.add(newPlanet.mesh);
        this.planets.push(newPlanet);
        
        // 5. 將畫面切換至剛新增好的這顆新星球
        this.currentIndex = this.planets.length - 1;
        slidePlanets(this.planets, this.currentIndex);
        this.updateHUD();
        
        setTimeout(() => {
            this.updateCharacter();
        }, 1000);

        console.log(`成功新增課程星球：${courseName}`);
    }

    // --- 建立課程加入 Modal 與其互動邏輯 ---
    createJoinModalDOM() {
        const modalHTML = `
            <div id="joinModal" class="modal-overlay">
                <div class="modal-box">
                    <h3>加入新課程</h3>
                    <p style="font-size: 14px; color: #000000; margin-bottom: 15px;">請輸入老師提供的課程代碼</p>
                    <input type="text" id="courseCodeInput" placeholder="例如：MATH101" />
                    <div class="modal-actions">
                        <button class="btn-cancel" id="closeModalBtn">取消</button>
                        <button class="btn-confirm" id="submitJoinBtn">確認加入</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // 綁定關閉按鈕事件
        document.getElementById('closeModalBtn').addEventListener('click', () => {
            document.getElementById('joinModal').classList.remove('active');
        });

        // 綁定確認送出事件（此處對接功能一：新增課程產生星球）
        document.getElementById('submitJoinBtn').addEventListener('click', () => {
            const code = document.getElementById('courseCodeInput').value.trim();
            if (!code) {
                alert('請輸入課程代碼！');
                return;
            }
            
            // 呼叫我們剛剛要實作的新增星球函式
            this.addNewPlanet(code);
            
            // 清空輸入框並關閉 Modal
            document.getElementById('courseCodeInput').value = '';
            document.getElementById('joinModal').classList.remove('active');
        });
    }

    createStudentInfoModalDOM() {
        // 1. 從 localStorage 讀取已儲存的姓名與學號（若沒有則給預設值）
        const savedName = localStorage.getItem("studentName") || "王小明";
        const savedId = localStorage.getItem("studentId") || "A11111111";

        const infoModalHTML = `
            <div id="studentInfoModal" class="modal-overlay">
                <div class="modal-box">
                    <h3>學生個人檔案</h3>
                    <div class="student-info-content">
                        <div style="margin-bottom: 12px; text-align: left;">
                            <label style="font-size: 14px; color: #101010; display: block; margin-bottom: 4px;">姓名：</label>
                            <input type="text" id="editStudentName" value="${savedName}" style="width: 100%; padding: 8px; background: #d1d2d4; border: 1px solid #a0a0a0; color: #313131; border-radius: 6px; box-sizing: border-box;" />
                        </div>
                        <div style="margin-bottom: 12px; text-align: left;">
                            <label style="font-size: 14px; color: #101010; display: block; margin-bottom: 4px;">學號：</label>
                            <input type="text" id="editStudentId" value="${savedId}" style="width: 100%; padding: 8px; background: #d1d2d4; border: 1px solid #a0a0a0; color: #313131; border-radius: 6px; box-sizing: border-box;" />
                        </div>
                        <p style="margin: 8px 0; font-size: 14px; color: #e0e0e0;">身份：<span>Student (學生)</span></p>
                        <p style="margin: 8px 0; font-size: 14px; color: #e0e0e0;">已修課程：<span>數學、國文、英文、自然</span></p>
                    </div>
                    <div class="modal-actions" style="display: flex; gap: 10px;">
                        <button class="btn-cancel" id="closeInfoBtn" style="flex: 1;">關閉視窗</button>
                        <button class="btn-confirm" id="saveInfoBtn" style="flex: 1;">儲存資料</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', infoModalHTML);

        // 關閉視窗按鈕
        document.getElementById('closeInfoBtn').addEventListener('click', () => {
            document.getElementById('studentInfoModal').classList.remove('active');
        });

        // 儲存資料按鈕（對接 localStorage）
        document.getElementById('saveInfoBtn').addEventListener('click', () => {
            const newName = document.getElementById('editStudentName').value.trim();
            const newId = document.getElementById('editStudentId').value.trim();

            if (!newName || !newId) {
                alert('姓名與學號不得為空！');
                return;
            }

            // 使用 localStorage 儲存
            localStorage.setItem("studentName", newName);
            localStorage.setItem("studentId", newId);

            alert('個人資料儲存成功！');
            document.getElementById('studentInfoModal').classList.remove('active');
        });
    }

    bindEvents() {
        window.addEventListener("mousemove", (event) => {
            // 若有彈窗開啟，隱藏提示字樣並中斷 3D Hover
            if (
                document.querySelector(".modal-overlay.active") ||
                document.querySelector(".quiz-modal-overlay.active") ||
                event.target.closest(".modal-overlay.active") ||
                event.target.closest(".quiz-modal-overlay.active")
            ) {
                this.updateHoverTooltip(null);
                document.body.style.cursor = "default";
                return;
            }

            this.mouse.x = (event.clientX / window.innerWidth) * 2 - 1;
            this.mouse.y = -(event.clientY / window.innerHeight) * 2 + 1;
            this.raycaster.setFromCamera(this.mouse, this.camera);

            const objects = this.planets.map(p => p.mesh);
            objects.push(this.character.mesh);
            objects.push(this.joinPlanet.mesh);

            // 進入考試場景後，將書本、鉛筆、旗幟納入射線偵測
            if (this.isExamMode && this.examGroup) {
                this.examGroup.traverse(child => {
                    if (child.isMesh) objects.push(child);
                });
            }

            const intersects = this.raycaster.intersectObjects(objects, true);

            // 重置游標、發光與提示字樣
            document.body.style.cursor = "default";
            this.updateHoverTooltip(null);
            this.planets.forEach(p => p.mesh.material.emissive.setHex(0x000000));
            this.joinPlanet.mesh.material.emissive.setHex(0x000000);
            this.character.mesh.traverse(child => {
                if (child.isMesh && child.material.emissive) {
                    child.material.emissive.setHex(0x000000);
                }
            });
            if (this.examGroup) {
                this.examGroup.traverse(child => {
                    if (child.isMesh && child.material.emissive) {
                        child.material.emissive.setHex(0x000000);
                    }
                });
            }

            if (intersects.length > 0) {
                const hovered = intersects[0].object;

                if (
                    this.joinPlanet &&
                    (hovered === this.joinPlanet.mesh || this.joinPlanet.mesh.getObjectById(hovered.id))
                ) {
                    document.body.style.cursor = "pointer";
                    this.updateHoverTooltip("加入課程", event.clientX, event.clientY);
                    if (this.joinPlanet.mesh.material && this.joinPlanet.mesh.material.emissive) {
                        this.joinPlanet.mesh.material.emissive.setHex(0x00aaff);
                        this.joinPlanet.mesh.material.emissiveIntensity = 0.8;
                    }
                    return;
                }

                // 往上尋找所屬群組的 userData.type（適用於 Group 結構的書本與鉛筆）
                let targetObj = hovered;
                let objectType = targetObj.userData.type;
                while (!objectType && targetObj.parent) {
                    targetObj = targetObj.parent;
                    objectType = targetObj.userData.type;
                }

                if (this.isExamMode && objectType === "exam_book") {
                    document.body.style.cursor = "pointer";
                    this.updateHoverTooltip("成績與解析", event.clientX, event.clientY);
                    targetObj.traverse(child => {
                        if (child.isMesh && child.material.emissive) {
                            child.material.emissive.setHex(0x444444);
                        }
                    });
                    return;
                }

                if (this.isExamMode && objectType === "exam_pencil") {
                    document.body.style.cursor = "pointer";
                    this.updateHoverTooltip("考試清單", event.clientX, event.clientY);
                    targetObj.traverse(child => {
                        if (child.isMesh && child.material.emissive) {
                            child.material.emissive.setHex(0x444444);
                        }
                    });
                    return;
                }

                // 原有星球與角色 Hover 邏輯
                if (hovered.userData.type === "planet" || hovered === this.joinPlanet.mesh) {
                    document.body.style.cursor = "pointer";
                    let rootMesh = hovered;
                    while (rootMesh.parent && rootMesh.parent !== this.scene) {
                        if (rootMesh.parent.type === "Mesh") rootMesh = rootMesh.parent;
                        else break;
                    }
                    if (rootMesh.material && rootMesh.material.emissive) {
                        rootMesh.material.emissive.setHex(0xffaa00);
                        rootMesh.material.emissiveIntensity = 0.5;
                    }
                } else if (hovered === this.character.mesh || this.character.mesh.getObjectById(hovered.id)) {
                    document.body.style.cursor = "pointer";
                }
        }
    });

        window.addEventListener("click", (event) => {
            // 1. 如果正在播放動畫，直接返回
            if (this.isAnimating) return;

            // 2. 如果點擊的是任何 Modal 內部容器或當前已有 Modal 啟用中，直接返回，避免穿透點擊到 3D 物件
            if (event.target.closest('.modal-box') || event.target.closest('.quiz-box')) return;
            if (document.querySelector('.modal-overlay.active') || document.querySelector('.quiz-modal-overlay.active')) return;

            this.mouse.x = (event.clientX / window.innerWidth) * 2 - 1;
            this.mouse.y = -(event.clientY / window.innerHeight) * 2 + 1;
            this.raycaster.setFromCamera(this.mouse, this.camera);

            const objects = this.planets.map(p => p.mesh);
            objects.push(this.character.mesh);
            objects.push(this.joinPlanet.mesh);

            // 如果在考試模式下，把考試場景中的物件（鉛筆、書本等）加入點擊判定
            if (this.isExamMode && this.examGroup) {
                this.examGroup.traverse(child => {
                    if (child.isMesh) objects.push(child);
                });
            }

            const intersects = this.raycaster.intersectObjects(objects, true);
            if (intersects.length === 0) return;

            const clicked = intersects[0].object;

            // 檢查點擊到什麼
            if (clicked === this.joinPlanet.mesh || clicked.parent === this.joinPlanet.mesh) {
                document.getElementById('joinModal').classList.add('active');
                return;
            }

            if (clicked === this.character.mesh || this.character.mesh.getObjectById(clicked.id)) {
                document.getElementById('studentInfoModal').classList.add('active');
                return;
            }

            // 檢查是否點擊到考試鉛筆
            if (clicked.userData.type === "exam_pencil" || clicked.parent?.userData.type === "exam_pencil") {
                if (this.currentExamPlanet) {
                    this.openExamList(this.currentExamPlanet.name);
                }
                return;
            }

            // 檢查是否點擊到成績書本
            if (clicked.userData.type === "exam_book" || clicked.parent?.userData.type === "exam_book") {
                if (this.currentExamPlanet) {
                    this.openResultPage(this.currentExamPlanet.name);
                }
                return;
            }

            if (clicked.userData.type === "planet") {
                if (this.isAnimating || this.isExamMode) return;

                const targetIndex = this.planets.findIndex(p => p.mesh === clicked || p.mesh.getObjectById(clicked.id));
                
                if (targetIndex !== -1) {
                    if (targetIndex !== this.currentIndex) {
                        console.log("只能進入正前方當前選中的星球考試！");
                        return;
                    }
                    this.isAnimating = true;
                    const targetPlanet = this.planets[targetIndex];
                    if (targetPlanet) {
                        this.enterExamScene(targetPlanet);
                    }
                }
            }
        });
    }

    nextPlanet() {
        if (this.isExamMode || this.isAnimating) return;

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
        if (this.isExamMode || this.isAnimating) return;  

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

    createQuizModalDOM() {
        const quizModalHTML = `
            <div id="quizModal" class="quiz-modal-overlay">
                <div class="quiz-box">
                    <h3 id="quizSubjectTitle">科目測驗</h3>
                    <div id="quizContainer">
                        <!-- 動態填入題目與選項 -->
                    </div>
                    <div class="modal-actions" style="margin-top: 20px;">
                        <button class="btn-cancel" id="closeQuizBtn">放棄測驗</button>
                        <button class="btn-confirm" id="nextQuizBtn">下一題</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', quizModalHTML);

        // 放棄測驗按鈕
        document.getElementById('closeQuizBtn').addEventListener('click', () => {
            document.getElementById('quizModal').classList.remove('active');
        });

        // 下一題 / 提交按鈕事件
        document.getElementById('nextQuizBtn').addEventListener('click', () => {
            this.handleNextQuestion();
        });
    }

    // 開始測驗
    startQuiz(subjectName) {
        this.currentSubject = subjectName;
        this.currentQuestionIndex = 0;
        this.userAnswers = [];

        // 模擬題庫資料
        this.questions = [
            {
                title: "1 + 1 = ?",
                options: ["1", "2", "3", "4"],
                answer: 1 //對應選項索引 (0型陣列，這裡是 '2')
            },
            {
                title: "2 + 2 = ?",
                options: ["1", "3", "4", "5"],
                answer: 2 //對應選項索引 ('4')
            }
        ];

        document.getElementById('quizSubjectTitle').innerText = `【${subjectName}】線上測驗`;
        document.getElementById('quizModal').classList.add('active');
        this.renderQuestion();
    }

    // 渲染當前題目
    renderQuestion() {
        const container = document.getElementById('quizContainer');
        const q = this.questions[this.currentQuestionIndex];
        
        let optionsHtml = '';
        q.options.forEach((opt, idx) => {
            optionsHtml += `
                <label class="option-label">
                    <input type="radio" name="quizOption" value="${idx}" />
                    ${opt}
                </label>
            `;
        });

        container.innerHTML = `
            <p style="color: #ffe84a; font-size: 14px; margin-bottom: 8px;">第 ${this.currentQuestionIndex + 1} 題 / 共 ${this.questions.length} 題</p>
            <div class="question-title">${q.title}</div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                ${optionsHtml}
            </div>
        `;

        // 如果是最後一題，按鈕文字改為「提交答案」
        const btn = document.getElementById('nextQuizBtn');
        if (this.currentQuestionIndex === this.questions.length - 1) {
            btn.innerText = "提交答案";
        } else {
            btn.innerText = "下一題";
        }
    }

    // 處理下一題或計算成績
    handleNextQuestion() {
        const selected = document.querySelector('input[name="quizOption"]:checked');
        if (!selected) {
            alert('請先選擇一個答案！');
            return;
        }

        // 記錄答案
        this.userAnswers.push(parseInt(selected.value));

        this.currentQuestionIndex++;
        if (this.currentQuestionIndex < this.questions.length) {
            this.renderQuestion();
        } else {
            // 計算成績
            this.calculateExamScore();
        }
    }

    // 計算成績並儲存
    calculateExamScore() {
        let correctCount = 0;
        this.questions.forEach((q, idx) => {
            if (this.userAnswers[idx] === q.answer) {
                correctCount++;
            }
        });

        const score = Math.round((correctCount / this.questions.length) * 100);
        const wrongCount = this.questions.length - correctCount;

        alert(`測驗結束！您的成績是：${score} 分 (答對 ${correctCount} 題，答錯 ${wrongCount} 題)`);

        // 關閉測驗面板
        document.getElementById('quizModal').classList.remove('active');

        // 將成績暫存或更新至目前星球的資料中
        if (this.currentExamPlanet) {
            this.currentExamPlanet.mesh.userData.details.avgScore = score;
            this.currentExamPlanet.mesh.userData.details.exams += 1;
            this.updateHUD(); // 更新畫面上方 HUD
        }
    }

    // 1. 建立「選擇考試場次」的 Modal DOM
    createExamSelectModalDOM() {
        const selectModalHTML = `
            <div id="examSelectModal" class="quiz-modal-overlay">
                <div class="quiz-box" style="width: 480px;">
                    <h3 id="examSelectTitle">選擇考試場次</h3>
                    <p style="font-size: 14px; color: #2a2a2a; margin-bottom: 15px;">請選擇您要進行的測驗項目：</p>
                    <div id="examListContainer" style="display: flex; flex-direction: column; gap: 10px; max-height: 300px; overflow-y: auto;">
                        <!-- 動態產生考試場次清單 -->
                    </div>
                    <div class="modal-actions" style="margin-top: 20px;">
                        <button class="btn-cancel" id="closeExamSelectBtn" style="width: 100%;">返回星球表面</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', selectModalHTML);

        // 關閉場次選擇視窗
        document.getElementById('closeExamSelectBtn').addEventListener('click', () => {
            document.getElementById('examSelectModal').classList.remove('active');
        });
    }

    // 2. 開啟某科目的考試場次列表
    openExamList(subjectName) {
        document.getElementById('examSelectTitle').innerText = `【${subjectName}】可考測驗列表`;
        const container = document.getElementById('examListContainer');

        // 模擬該科目的多場考試（未來可替換為後端 API 資料）
        const availableExams = [
            { id: 101, title: `${subjectName} - 第一次隨堂小考`, time: "10 分鐘", questionsCount: 2, status: "可作答" },
            { id: 102, title: `${subjectName} - 期中模擬測驗`, time: "30 分鐘", questionsCount: 2, status: "可作答" },
            { id: 103, title: `${subjectName} - 期末總複習卷`, time: "45 分鐘", questionsCount: 2, status: "可作答" }
        ];

        container.innerHTML = '';
        availableExams.forEach(exam => {
            const itemHTML = `
                <div class="exam-item-card" data-exam-id="${exam.id}" data-exam-title="${exam.title}">
                    <div>
                        <div class="exam-item-title">${exam.title}</div>
                        <div class="exam-item-meta">題數：${exam.questionsCount} 題 ｜ 限時：${exam.time}</div>
                    </div>
                    <span class="exam-item-btn">開始作答 ➔</span>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHTML);
        });

        // 綁定每一張考試卡片的點擊事件（Hover 效果已交由 CSS :hover 處理）
        container.querySelectorAll('.exam-item-card').forEach(card => {
            card.addEventListener('click', () => {
                const examTitle = card.getAttribute('data-exam-title');
                document.getElementById('examSelectModal').classList.remove('active');
                this.startQuiz(examTitle);
            });
        });

        document.getElementById('examSelectModal').classList.add('active');
    }

    // 1. 建立成績與解析 Modal DOM
    createResultModalDOM() {
        const resultModalHTML = `
            <div id="resultModal" class="quiz-modal-overlay">
                <div class="result-box">
                    <h3 id="resultModalTitle">學習成績與錯題解析</h3>
                    <div id="resultContentContainer">
                        <!-- 動態填入成績與錯題資訊 -->
                    </div>
                    <div class="modal-actions" style="margin-top: 20px;">
                        <button class="btn-confirm" id="closeResultBtn" style="width: 100%;">關閉視窗</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', resultModalHTML);

        document.getElementById('closeResultBtn').addEventListener('click', () => {
            document.getElementById('resultModal').classList.remove('active');
        });
    }

    // 2. 開啟成績與解析頁面
    openResultPage(subjectName) {
        document.getElementById('resultModalTitle').innerText = `【${subjectName}】成績與錯題解析`;
        const container = document.getElementById('resultContentContainer');

        // 讀取該星球目前的平均分數（若剛考完會同步更新）
        const currentDetails = this.currentExamPlanet?.mesh.userData.details || { avgScore: 0, exams: 0 };
        const score = currentDetails.avgScore;
        const correctCount = score > 0 ? Math.round((score / 100) * 2) : 0;
        const wrongCount = 2 - correctCount;

        container.innerHTML = `
            <div class="result-stat-card">
                <div class="result-stat-item">
                    <p style="font-size: 14px; color: #aaa;">最新成績</p>
                    <span>${score} 分</span>
                </div>
                <div class="result-stat-item">
                    <p style="font-size: 14px; color: #aaa;">答對題數</p>
                    <span style="color: #63ff7f;">${correctCount} 題</span>
                </div>
                <div class="result-stat-item">
                    <p style="font-size: 14px; color: #aaa;">答錯題數</p>
                    <span style="color: #e94560;">${wrongCount} 題</span>
                </div>
            </div>

            <h4 style="color: #232323; margin-bottom: 10px; font-size: 16px;">錯題解析記錄：</h4>
            <div id="wrongQuestionsList">
                ${wrongCount > 0 ? `
                    <div class="wrong-question-item">
                        <div style="font-weight: bold; color: #fff; margin-bottom: 4px;">Q: 1 + 1 = ?</div>
                        <div style="font-size: 13px; color: #df1f1f;">你的答案：1 ｜ 正確答案：2</div>
                        <div style="font-size: 12px; color: #393939; margin-top: 4px;">解析：基本加法運算，1加1等於2。</div>
                    </div>
                ` : `<p style="color: #3e3e3e; text-align: center; padding: 20px;">太棒了！目前沒有錯題紀錄，保持得非常好！</p>`}
            </div>
        `;

        document.getElementById('resultModal').classList.add('active');
    }
}
