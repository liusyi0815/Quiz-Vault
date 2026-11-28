import * as THREE from "three";
import gsap from "gsap";
import { Planet } from "../objects/Planet";
import { Character } from "../objects/Character";
import { slidePlanets } from "../animations/PlanetSlide";
import { flyToPlanet } from "../animations/CameraFly";
import { createFlag, createAttendanceBook, createRedPencil } from "../objects/ExamObjects";

export class TeacherScene {
    constructor(scene, camera) {
        this.scene = scene;
        this.camera = camera;
        this.planets = [];
        this.currentIndex = 0;
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2();
        this.isAnimating = false;
        this.isExamMode = false;
        this.currentExamPlanet = null;
        this.examGroup = null;
        this.isInitialized = false;

        // 老師端模擬考卷與學生作答結果資料（依科目名稱存放）
        this.teacherExamsBySubject = {
            "數學": [
                {
                    id: 201,
                    title: "數學 - 第一次隨堂小考",
                    time: "15 分鐘",
                    questions: [
                        { title: "1 + 1 = ?", options: ["1", "2", "3", "4"], answer: 1 },
                        { title: "2 + 2 = ?", options: ["1", "3", "4", "5"], answer: 2 }
                    ]
                },
                {
                    id: 202,
                    title: "數學 - 期中模擬測驗",
                    time: "40 分鐘",
                    questions: [
                        { title: "5 × 6 = ?", options: ["11", "25", "30", "36"], answer: 2 }
                    ]
                }
            ],
            "國文": [
                {
                    id: 301,
                    title: "國文 - 成語與文意測驗",
                    time: "20 分鐘",
                    questions: [
                        { title: "「亡羊補牢」的意思接近下列何者？", options: ["事後補救", "事前預防", "徒勞無功", "趁火打劫"], answer: 0 }
                    ]
                }
            ],
            "英文": [
                {
                    id: 401,
                    title: "英文 - 核心單字小考",
                    time: "15 分鐘",
                    questions: [
                        { title: "「Planet」的中文意思是？", options: ["星星", "星球", "太空船", "銀河"], answer: 1 }
                    ]
                }
            ],
            "自然": [
                {
                    id: 501,
                    title: "自然 - 太陽系與重力測驗",
                    time: "25 分鐘",
                    questions: [
                        { title: "地球繞太陽公轉一圈大約需要多久？", options: ["一天", "一個月", "一年", "十年"], answer: 2 }
                    ]
                }
            ]
        };

        this.studentResultsBySubject = {
            "數學": [
                { studentId: "A11111111", name: "王小明", examTitle: "數學 - 第一次隨堂小考", score: 100, wrongCount: 0, submittedAt: "2026-11-20 14:10" },
                { studentId: "A11111112", name: "林小華", examTitle: "數學 - 第一次隨堂小考", score: 50, wrongCount: 1, submittedAt: "2026-11-20 14:15" },
                { studentId: "A11111113", name: "陳大雄", examTitle: "數學 - 第一次隨堂小考", score: 100, wrongCount: 0, submittedAt: "2026-11-20 14:18" }
            ],
            "國文": [
                { studentId: "A11111111", name: "王小明", examTitle: "國文 - 成語與文意測驗", score: 100, wrongCount: 0, submittedAt: "2026-11-21 09:30" },
                { studentId: "A11111112", name: "林小華", examTitle: "國文 - 成語與文意測驗", score: 0, wrongCount: 1, submittedAt: "2026-11-21 09:35" }
            ],
            "英文": [
                { studentId: "A11111111", name: "王小明", examTitle: "英文 - 核心單字小考", score: 100, wrongCount: 0, submittedAt: "2026-11-22 10:05" }
            ],
            "自然": [
                { studentId: "A11111112", name: "林小華", examTitle: "自然 - 太陽系與重力測驗", score: 100, wrongCount: 0, submittedAt: "2026-11-23 16:20" }
            ]
        };
    }

    init() {
        if (this.isInitialized) return;
        this.isInitialized = true;

        if (!this.scene.children.some(obj => obj.isLight)) {
            this.createLights();
        }
        if (!this.starField) {
            this.createStars();
        }

        this.createOrbits();
        this.createPlanets();
        this.createCreateCoursePlanet();
        this.createCharacter();

        this.createHoverTooltipDOM();
        this.createCreateCourseModalDOM();
        this.createTeacherInfoModalDOM();
        this.createSubjectHUDDOM();
        this.createBackBtnDOM();
        this.createExamEditorModalDOM();
        this.createExamResultsModalDOM();

        this.bindEvents();
    }

    createBackBtnDOM() {
        const btnHTML = `<button id="teacherBackGalaxyBtn" class="back-btn">⬅ 返回宇宙總覽</button>`;
        document.body.insertAdjacentHTML("beforeend", btnHTML);
        document.getElementById("teacherBackGalaxyBtn").addEventListener("click", () => {
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
        const material = new THREE.LineBasicMaterial({
            color: 0xffe84a,
            transparent: true,
            opacity: 0.6
        });

        for (let i = 0; i < 5; i++) {
            const curve = new THREE.EllipseCurve(
                0, 3,
                25 + i * 15, 3 + i * 1,
                Math.PI * 1.25,
                Math.PI * 1.249,
                false,
                Math.PI * 0.03
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

    getSubjectStats(subjectName) {
        const exams = this.teacherExamsBySubject[subjectName] || [];
        const results = this.studentResultsBySubject[subjectName] || [];
        const avgScore = results.length > 0
            ? Math.round(results.reduce((sum, r) => sum + r.score, 0) / results.length)
            : 0;
        return {
            examsCount: exams.length,
            studentsCount: results.length,
            avgScore
        };
    }

    createPlanets() {
        const subjects = [
            { name: "數學", color: 0x89d8ff, texture: "/planet5.jpg", level: "Lv. 2" },
            { name: "國文", color: 0xffe84a, texture: "/planet6.jpg", level: "Lv. 1" },
            { name: "英文", color: 0x63ff7f, texture: "/planet7.jpg", level: "Lv. 3" },
            { name: "自然", color: 0xff8cdc, texture: "/planet8.jpg", level: "Lv. 1" }
        ];

        subjects.forEach((subject, index) => {
            const planet = new Planet(subject.name, subject.color, index * 12, 3, subject.texture);
            const stats = this.getSubjectStats(subject.name);
            planet.mesh.userData.details = {
                level: subject.level,
                exams: stats.examsCount,
                avgScore: stats.avgScore
            };
            this.scene.add(planet.mesh);
            this.planets.push(planet);
        });

        slidePlanets(this.planets, this.currentIndex);
    }

    createCreateCoursePlanet() {
        this.createCoursePlanet = new Planet("創建課程", 0xffd700, 0, 2.0, "/join-planet-teacher.jpg");
        this.createCoursePlanet.mesh.position.set(-20, 15, 0);
        this.createCoursePlanet.mesh.userData.type = "create_course_planet";
        this.scene.add(this.createCoursePlanet.mesh);
    }

    createHoverTooltipDOM() {
        if (document.getElementById("teacherHoverTooltip")) return;
        const tooltipHTML = `<div id="teacherHoverTooltip" class="hover-tooltip"></div>`;
        document.body.insertAdjacentHTML("beforeend", tooltipHTML);
        this.hoverTooltip = document.getElementById("teacherHoverTooltip");
    }

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
        if (currentPlanet && this.character) {
            this.character.mesh.position.copy(currentPlanet.mesh.position);
            this.character.mesh.position.y = currentPlanet.mesh.position.y + 6.5;
        }
    }

    createSubjectHUDDOM() {
        if (document.getElementById("teacherSubjectHUD")) return;
        const hudHTML = `
            <div id="teacherSubjectHUD" class="subject-hud">
                <h2 id="teacherHudTitle">載入中...</h2>
                <p>目前等級：<span id="teacherHudLevel">-</span></p>
                <p>已建考卷：<span id="teacherHudExams">-</span> 份</p>
                <p>班級均分：<span id="teacherHudScore">-</span> 分</p>
            </div>
        `;
        document.body.insertAdjacentHTML("beforeend", hudHTML);
        this.updateHUD();
    }

    updateHUD() {
        const currentPlanet = this.planets[this.currentIndex];
        if (!currentPlanet) return;
        const stats = this.getSubjectStats(currentPlanet.name);
        currentPlanet.mesh.userData.details.exams = stats.examsCount;
        currentPlanet.mesh.userData.details.avgScore = stats.avgScore;

        document.getElementById("teacherHudTitle").innerText = `課程：${currentPlanet.name}`;
        document.getElementById("teacherHudLevel").innerText = currentPlanet.mesh.userData.details.level;
        document.getElementById("teacherHudExams").innerText = stats.examsCount;
        document.getElementById("teacherHudScore").innerText = stats.avgScore;
    }

    addNewCoursePlanet(courseName) {
        const textures = [
            "/planet5.jpg",
            "/planet6.jpg",
            "/planet7.jpg",
            "/planet8.jpg"
        ];
        const randomTexture = textures[Math.floor(Math.random() * textures.length)];

        const newIndex = this.planets.length;
        const newPlanet = new Planet(courseName, 0xffffff, newIndex * 12, 3, randomTexture);

        if (!this.teacherExamsBySubject[courseName]) {
            this.teacherExamsBySubject[courseName] = [];
        }
        if (!this.studentResultsBySubject[courseName]) {
            this.studentResultsBySubject[courseName] = [];
        }

        newPlanet.mesh.userData.details = {
            level: "Lv. 1",
            exams: 0,
            avgScore: 0
        };

        this.scene.add(newPlanet.mesh);
        this.planets.push(newPlanet);

        this.currentIndex = this.planets.length - 1;
        slidePlanets(this.planets, this.currentIndex);
        this.updateHUD();

        setTimeout(() => {
            this.updateCharacter();
        }, 1000);
    }

    createCreateCourseModalDOM() {
        if (document.getElementById("createCourseModal")) return;
        const modalHTML = `
            <div id="createCourseModal" class="modal-overlay">
                <div class="modal-box">
                    <h3>創建新課程星球</h3>
                    <p style="font-size: 14px; color: #000000; margin-bottom: 15px;">請輸入要建立的課程名稱或代碼</p>
                    <input type="text" id="newCourseNameInput" placeholder="例如：物理 或 PHYS101" />
                    <div class="modal-actions">
                        <button class="btn-cancel" id="closeCreateCourseBtn">取消</button>
                        <button class="btn-confirm" id="submitCreateCourseBtn">確認創建</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML("beforeend", modalHTML);

        document.getElementById("closeCreateCourseBtn").addEventListener("click", () => {
            document.getElementById("createCourseModal").classList.remove("active");
        });

        document.getElementById("submitCreateCourseBtn").addEventListener("click", () => {
            const inputEl = document.getElementById("newCourseNameInput");
            const courseName = inputEl.value.trim();
            if (!courseName) {
                alert("請輸入課程名稱或代碼！");
                return;
            }
            this.addNewCoursePlanet(courseName);
            inputEl.value = "";
            document.getElementById("createCourseModal").classList.remove("active");
        });
    }

    createTeacherInfoModalDOM() {
        if (document.getElementById("teacherInfoModal")) return;
        const savedName = localStorage.getItem("teacherName") || "林老師";
        const savedId = localStorage.getItem("teacherId") || "T10001";

        const infoModalHTML = `
            <div id="teacherInfoModal" class="modal-overlay">
                <div class="modal-box">
                    <h3>教師個人檔案</h3>
                    <div class="student-info-content">
                        <div style="margin-bottom: 12px; text-align: left;">
                            <label style="font-size: 14px; color: #101010; display: block; margin-bottom: 4px;">姓名：</label>
                            <input type="text" id="editTeacherName" value="${savedName}" style="width: 100%; padding: 8px; background: #d1d2d4; border: 1px solid #a0a0a0; color: #313131; border-radius: 6px; box-sizing: border-box;" />
                        </div>
                        <div style="margin-bottom: 12px; text-align: left;">
                            <label style="font-size: 14px; color: #101010; display: block; margin-bottom: 4px;">教師編號：</label>
                            <input type="text" id="editTeacherId" value="${savedId}" style="width: 100%; padding: 8px; background: #d1d2d4; border: 1px solid #a0a0a0; color: #313131; border-radius: 6px; box-sizing: border-box;" />
                        </div>
                        <p style="margin: 8px 0; font-size: 14px; color: #e0e0e0;">身份：<span>Teacher (教師)</span></p>
                    </div>
                    <div class="modal-actions" style="display: flex; gap: 10px;">
                        <button class="btn-cancel" id="closeTeacherInfoBtn" style="flex: 1;">關閉視窗</button>
                        <button class="btn-confirm" id="saveTeacherInfoBtn" style="flex: 1;">儲存資料</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML("beforeend", infoModalHTML);

        document.getElementById("closeTeacherInfoBtn").addEventListener("click", () => {
            document.getElementById("teacherInfoModal").classList.remove("active");
        });

        document.getElementById("saveTeacherInfoBtn").addEventListener("click", () => {
            const newName = document.getElementById("editTeacherName").value.trim();
            const newId = document.getElementById("editTeacherId").value.trim();
            if (!newName || !newId) {
                alert("姓名與教師編號不得為空！");
                return;
            }
            localStorage.setItem("teacherName", newName);
            localStorage.setItem("teacherId", newId);
            alert("教師資料儲存成功！");
            document.getElementById("teacherInfoModal").classList.remove("active");
        });
    }

    enterExamScene(planet) {
        this.isExamMode = true;
        this.currentExamPlanet = planet;

        const hudEl = document.getElementById("teacherSubjectHUD");
        if (hudEl) hudEl.style.display = "none";
        document.getElementById("teacherBackGalaxyBtn").classList.add("active");

        this.examGroup = new THREE.Group();

        const flag = createFlag(planet.name);
        flag.position.set(0.2, 2, -0.5);
        this.examGroup.add(flag);

        const book = createAttendanceBook();
        book.position.set(-1.8, 3, 0.8);
        this.examGroup.add(book);

        const pencil = createRedPencil();
        pencil.position.set(1.6, 4, 0.5);
        this.examGroup.add(pencil);

        planet.mesh.add(this.examGroup);

        flyToPlanet(this.camera, planet.mesh, () => {
            this.isAnimating = false;
        });
    }

    returnToGalaxy() {
        if (this.isAnimating) return;
        this.isAnimating = true;

        if (this.currentExamPlanet && this.examGroup) {
            this.currentExamPlanet.mesh.remove(this.examGroup);
            this.examGroup = null;
            this.currentExamPlanet = null;
        }

        document.getElementById("teacherBackGalaxyBtn").classList.remove("active");
        const hudEl = document.getElementById("teacherSubjectHUD");
        if (hudEl) hudEl.style.display = "block";
        this.updateHUD();

        const currentPlanet = this.planets[this.currentIndex];
        const targetPos = currentPlanet.mesh.position;

        const returnPosition = {
            x: targetPos.x,
            y: targetPos.y + 10,
            z: targetPos.z + 40
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
                this.camera.position.set(returnPosition.x, returnPosition.y, returnPosition.z);
                this.camera.lookAt(returnLookAt.x, returnLookAt.y, returnLookAt.z);
                this.isAnimating = false;
                this.isExamMode = false;
            }
        });
    }

    // 鉛筆點擊：編輯考試列表 Modal
    createExamEditorModalDOM() {
        if (document.getElementById("teacherExamEditorModal")) return;
        const modalHTML = `
            <div id="teacherExamEditorModal" class="quiz-modal-overlay">
                <div class="result-box" style="width: 560px;">
                    <h3 id="teacherExamEditorTitle">編輯考試列表</h3>

                    <div style="background: rgba(255,255,255,0.65); padding: 14px; border-radius: 10px; margin-bottom: 16px; color: #1a1a1a;">
                        <div style="font-weight: bold; margin-bottom: 8px;">新增考試場次</div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <input type="text" id="newExamTitleInput" placeholder="考卷名稱，例如：第二次小考" style="flex: 2; min-width: 180px; padding: 8px; border-radius: 6px; border: 1px solid #888;" />
                            <input type="text" id="newExamTimeInput" placeholder="限時（例如：20 分鐘）" style="flex: 1; min-width: 120px; padding: 8px; border-radius: 6px; border: 1px solid #888;" />
                            <button type="button" id="addExamItemBtn" class="btn-confirm" style="padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">＋ 新增考卷</button>
                        </div>
                    </div>

                    <div id="teacherExamListContainer" style="display: flex; flex-direction: column; gap: 10px; max-height: 280px; overflow-y: auto;"></div>

                    <div class="modal-actions" style="margin-top: 20px;">
                        <button type="button" class="btn-cancel" id="closeExamEditorBtn" style="width: 100%;">關閉視窗</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML("beforeend", modalHTML);

        document.getElementById("closeExamEditorBtn").addEventListener("click", () => {
            document.getElementById("teacherExamEditorModal").classList.remove("active");
            this.updateHUD();
        });

        document.getElementById("addExamItemBtn").addEventListener("click", () => {
            if (!this.currentExamPlanet) return;
            const subjectName = this.currentExamPlanet.name;
            const titleEl = document.getElementById("newExamTitleInput");
            const timeEl = document.getElementById("newExamTimeInput");
            const title = titleEl.value.trim();
            const time = timeEl.value.trim() || "20 分鐘";

            if (!title) {
                alert("請輸入考卷名稱！");
                return;
            }

            if (!this.teacherExamsBySubject[subjectName]) {
                this.teacherExamsBySubject[subjectName] = [];
            }

            this.teacherExamsBySubject[subjectName].push({
                id: Date.now(),
                title: title.includes(subjectName) ? title : `${subjectName} - ${title}`,
                time,
                questions: [
                    { title: "範例題目：1 + 1 = ?", options: ["1", "2", "3", "4"], answer: 1 }
                ]
            });

            titleEl.value = "";
            timeEl.value = "";
            this.renderTeacherExamList(subjectName);
            this.updateHUD();
        });
    }

    openExamEditor(subjectName) {
        document.getElementById("teacherExamEditorTitle").innerText = `【${subjectName}】編輯考試列表`;
        this.renderTeacherExamList(subjectName);
        document.getElementById("teacherExamEditorModal").classList.add("active");
    }

    renderTeacherExamList(subjectName) {
        const container = document.getElementById("teacherExamListContainer");
        const exams = this.teacherExamsBySubject[subjectName] || [];

        if (exams.length === 0) {
            container.innerHTML = `<p style="color: #333; text-align: center; padding: 16px;">目前尚無考卷，請在上方新增第一份測驗。</p>`;
            return;
        }

        container.innerHTML = "";
        exams.forEach((exam) => {
            const card = document.createElement("div");
            card.className = "exam-item-card";
            card.innerHTML = `
                <div>
                    <div class="exam-item-title">${exam.title}</div>
                    <div class="exam-item-meta">題數：${exam.questions.length} 題 ｜ 限時：${exam.time}</div>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="exam-item-btn edit-exam-btn" data-id="${exam.id}">編輯題目</button>
                    <button type="button" class="btn-cancel delete-exam-btn" data-id="${exam.id}" style="padding: 6px 10px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px;">刪除</button>
                </div>
            `;

            card.querySelector(".edit-exam-btn").addEventListener("click", () => {
                const firstQ = exam.questions[0];
                const updatedTitle = prompt("修改第一題題目內容：", firstQ ? firstQ.title : "1 + 1 = ?");
                if (updatedTitle && updatedTitle.trim()) {
                    if (firstQ) {
                        firstQ.title = updatedTitle.trim();
                    } else {
                        exam.questions.push({ title: updatedTitle.trim(), options: ["A", "B", "C", "D"], answer: 0 });
                    }
                    this.renderTeacherExamList(subjectName);
                }
            });

            card.querySelector(".delete-exam-btn").addEventListener("click", () => {
                this.teacherExamsBySubject[subjectName] = exams.filter(e => e.id !== exam.id);
                this.renderTeacherExamList(subjectName);
                this.updateHUD();
            });

            container.appendChild(card);
        });
    }

    // 書本點擊：考試結果列表 Modal
    createExamResultsModalDOM() {
        if (document.getElementById("teacherExamResultsModal")) return;
        const modalHTML = `
            <div id="teacherExamResultsModal" class="quiz-modal-overlay">
                <div class="result-box" style="width: 560px;">
                    <h3 id="teacherResultsTitle">考試結果列表</h3>
                    <div id="teacherResultsContent"></div>
                    <div class="modal-actions" style="margin-top: 20px;">
                        <button type="button" class="btn-confirm" id="closeExamResultsBtn" style="width: 100%;">關閉視窗</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML("beforeend", modalHTML);

        document.getElementById("closeExamResultsBtn").addEventListener("click", () => {
            document.getElementById("teacherExamResultsModal").classList.remove("active");
        });
    }

    openExamResultsList(subjectName) {
        document.getElementById("teacherResultsTitle").innerText = `【${subjectName}】考試結果列表`;
        const container = document.getElementById("teacherResultsContent");
        const results = this.studentResultsBySubject[subjectName] || [];

        const avgScore = results.length > 0
            ? Math.round(results.reduce((sum, item) => sum + item.score, 0) / results.length)
            : 0;
        const passCount = results.filter(item => item.score >= 60).length;

        const rowsHTML = results.length > 0
            ? results.map(r => `
                <div class="wrong-question-item" style="background: rgba(47, 56, 62, 0.85); border-color: rgba(255, 215, 0, 0.45); color: #fff; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                    <div>
                        <div style="font-weight: bold; color: #ffd700;">${r.name}（${r.studentId}）</div>
                        <div style="font-size: 13px; color: #e5e7eb; margin-top: 2px;">${r.examTitle}</div>
                        <div style="font-size: 12px; color: #9ca3af; margin-top: 2px;">繳交時間：${r.submittedAt}</div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 18px; font-weight: bold; color: ${r.score >= 60 ? "#63ff7f" : "#ff6b81"};">${r.score} 分</div>
                        <div style="font-size: 12px; color: #ddd;">錯題：${r.wrongCount} 題</div>
                    </div>
                </div>
            `).join("")
            : `<p style="color: #333; text-align: center; padding: 20px;">目前尚無學生作答紀錄。</p>`;

        container.innerHTML = `
            <div class="result-stat-card">
                <div class="result-stat-item">
                    <p style="font-size: 14px; color: #aaa;">作答份數</p>
                    <span>${results.length} 份</span>
                </div>
                <div class="result-stat-item">
                    <p style="font-size: 14px; color: #aaa;">平均分數</p>
                    <span style="color: #ffd700;">${avgScore} 分</span>
                </div>
                <div class="result-stat-item">
                    <p style="font-size: 14px; color: #aaa;">及格人數</p>
                    <span style="color: #63ff7f;">${passCount} 人</span>
                </div>
            </div>
            <h4 style="color: #232323; margin-bottom: 10px; font-size: 16px;">學生作答明細：</h4>
            <div style="display: flex; flex-direction: column; gap: 8px; max-height: 260px; overflow-y: auto;">
                ${rowsHTML}
            </div>
        `;

        document.getElementById("teacherExamResultsModal").classList.add("active");
    }

    bindEvents() {
        window.addEventListener("mousemove", (event) => {
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
            if (this.character) objects.push(this.character.mesh);
            if (this.createCoursePlanet) objects.push(this.createCoursePlanet.mesh);

            if (this.isExamMode && this.examGroup) {
                this.examGroup.traverse(child => {
                    if (child.isMesh) objects.push(child);
                });
            }

            const intersects = this.raycaster.intersectObjects(objects, true);

            document.body.style.cursor = "default";
            this.updateHoverTooltip(null);
            this.planets.forEach(p => p.mesh.material.emissive.setHex(0x000000));
            if (this.createCoursePlanet) {
                this.createCoursePlanet.mesh.material.emissive.setHex(0x000000);
            }
            if (this.character) {
                this.character.mesh.traverse(child => {
                    if (child.isMesh && child.material.emissive) {
                        child.material.emissive.setHex(0x000000);
                    }
                });
            }
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
                    this.createCoursePlanet &&
                    (hovered === this.createCoursePlanet.mesh || this.createCoursePlanet.mesh.getObjectById(hovered.id))
                ) {
                    document.body.style.cursor = "pointer";
                    this.updateHoverTooltip("創建課程", event.clientX, event.clientY);
                    if (this.createCoursePlanet.mesh.material && this.createCoursePlanet.mesh.material.emissive) {
                        this.createCoursePlanet.mesh.material.emissive.setHex(0xffd700);
                        this.createCoursePlanet.mesh.material.emissiveIntensity = 0.7;
                    }
                    return;
                }

                let targetObj = hovered;
                let objectType = targetObj.userData.type;
                while (!objectType && targetObj.parent) {
                    targetObj = targetObj.parent;
                    objectType = targetObj.userData.type;
                }

                if (this.isExamMode && objectType === "exam_book") {
                    document.body.style.cursor = "pointer";
                    this.updateHoverTooltip("考試結果列表", event.clientX, event.clientY);
                    targetObj.traverse(child => {
                        if (child.isMesh && child.material.emissive) {
                            child.material.emissive.setHex(0x444444);
                        }
                    });
                    return;
                }

                if (this.isExamMode && objectType === "exam_pencil") {
                    document.body.style.cursor = "pointer";
                    this.updateHoverTooltip("編輯考試列表", event.clientX, event.clientY);
                    targetObj.traverse(child => {
                        if (child.isMesh && child.material.emissive) {
                            child.material.emissive.setHex(0x444444);
                        }
                    });
                    return;
                }

                if (hovered.userData.type === "planet") {
                    document.body.style.cursor = "pointer";
                    if (hovered.material && hovered.material.emissive) {
                        hovered.material.emissive.setHex(0xffaa00);
                        hovered.material.emissiveIntensity = 0.5;
                    }
                } else if (this.character && (hovered === this.character.mesh || this.character.mesh.getObjectById(hovered.id))) {
                    document.body.style.cursor = "pointer";
                }
            }
        });

        window.addEventListener("click", (event) => {
            if (this.isAnimating) return;
            if (event.target.closest(".modal-box") || event.target.closest(".quiz-box") || event.target.closest(".result-box")) return;
            if (document.querySelector(".modal-overlay.active") || document.querySelector(".quiz-modal-overlay.active")) return;

            this.mouse.x = (event.clientX / window.innerWidth) * 2 - 1;
            this.mouse.y = -(event.clientY / window.innerHeight) * 2 + 1;
            this.raycaster.setFromCamera(this.mouse, this.camera);

            const objects = this.planets.map(p => p.mesh);
            if (this.character) objects.push(this.character.mesh);
            if (this.createCoursePlanet) objects.push(this.createCoursePlanet.mesh);

            if (this.isExamMode && this.examGroup) {
                this.examGroup.traverse(child => {
                    if (child.isMesh) objects.push(child);
                });
            }

            const intersects = this.raycaster.intersectObjects(objects, true);
            if (intersects.length === 0) return;

            const clicked = intersects[0].object;

            if (
                this.createCoursePlanet &&
                (clicked === this.createCoursePlanet.mesh || clicked.parent === this.createCoursePlanet.mesh)
            ) {
                document.getElementById("createCourseModal").classList.add("active");
                return;
            }

            if (this.character && (clicked === this.character.mesh || this.character.mesh.getObjectById(clicked.id))) {
                document.getElementById("teacherInfoModal").classList.add("active");
                return;
            }

            if (clicked.userData.type === "exam_pencil" || clicked.parent?.userData.type === "exam_pencil") {
                if (this.currentExamPlanet) {
                    this.openExamEditor(this.currentExamPlanet.name);
                }
                return;
            }

            if (clicked.userData.type === "exam_book" || clicked.parent?.userData.type === "exam_book") {
                if (this.currentExamPlanet) {
                    this.openExamResultsList(this.currentExamPlanet.name);
                }
                return;
            }

            if (clicked.userData.type === "planet") {
                if (this.isAnimating || this.isExamMode) return;
                const targetIndex = this.planets.findIndex(p => p.mesh === clicked || p.mesh.getObjectById(clicked.id));
                if (targetIndex !== -1) {
                    if (targetIndex !== this.currentIndex) return;
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
        if (this.isExamMode || this.isAnimating || this.planets.length === 0) return;
        this.currentIndex++;
        if (this.currentIndex >= this.planets.length) {
            this.currentIndex = 0;
        }
        slidePlanets(this.planets, this.currentIndex);
        this.updateHUD();
        setTimeout(() => {
            this.updateCharacter();
        }, 1000);
    }

    previousPlanet() {
        if (this.isExamMode || this.isAnimating || this.planets.length === 0) return;
        this.currentIndex--;
        if (this.currentIndex < 0) {
            this.currentIndex = this.planets.length - 1;
        }
        slidePlanets(this.planets, this.currentIndex);
        this.updateHUD();
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
        if (this.createCoursePlanet) {
            this.createCoursePlanet.mesh.rotation.y += 0.005;
        }
        this.updateCharacter();
    }
}