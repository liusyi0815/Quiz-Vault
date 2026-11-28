import gsap from "gsap";

export class AuthTransition {
    /**
     * @param {Object} options
     * @param {string} [options.skyImage="/sky-bg.png"] - 天空背景圖路徑
     * @param {string} [options.studentUfoImage="/ufo-student.png"] - 學生幽浮圖路徑
     * @param {string} [options.teacherUfoImage="/ufo-teacher.png"] - 老師幽浮圖路徑
     * @param {(role: "student" | "teacher") => void} [options.onComplete] - 轉場完成回調
     */
    constructor(options = {}) {
        this.skyImage = options.skyImage || "/sky-bg.png";
        this.studentUfoImage = options.studentUfoImage || "/ufo-student.png";
        this.teacherUfoImage = options.teacherUfoImage || "/ufo-teacher.png";
        this.onComplete = options.onComplete || (() => {});
        this.isTransitioning = false;
        this.rootEl = null;
    }

    mount() {
        if (this.rootEl) return;

        const html = `
            <div id="roleSelectLayer" class="role-select-layer">
                <div id="skyBackdrop" class="sky-backdrop" style="background-image: url('${this.skyImage}');"></div>

                <div id="roleContent" class="role-content">
                    <h1 class="role-title">請選擇身分</h1>

                    <div class="ufo-stage">
                        <button type="button" class="ufo-card" data-role="student" aria-label="學生">
                            <img src="${this.studentUfoImage}" alt="學生 UFO" class="ufo-img ufo-student-img" />
                            <span class="ufo-label">學生</span>
                        </button>

                        <button type="button" class="ufo-card" data-role="teacher" aria-label="老師">
                            <img src="${this.teacherUfoImage}" alt="老師 UFO" class="ufo-img ufo-teacher-img" />
                            <span class="ufo-label">老師</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML("beforeend", html);
        this.rootEl = document.getElementById("roleSelectLayer");
        this.bindEvents();
    }

    bindEvents() {
        const cards = this.rootEl.querySelectorAll(".ufo-card");
        cards.forEach((card) => {
            card.addEventListener("click", () => {
                if (this.isTransitioning) return;
                const role = card.getAttribute("data-role");
                this.playSelectAnimation(role, card);
            });
        });
    }

    playSelectAnimation(selectedRole, selectedCard) {
        this.isTransitioning = true;
        this.rootEl.style.pointerEvents = "none";

        const skyEl = document.getElementById("skyBackdrop");
        const titleEl = this.rootEl.querySelector(".role-title");
        const allCards = Array.from(this.rootEl.querySelectorAll(".ufo-card"));
        const otherCards = allCards.filter((c) => c !== selectedCard);
        const selectedLabel = selectedCard.querySelector(".ufo-label");

        const tl = gsap.timeline({
            onComplete: () => {
                this.rootEl.style.display = "none";
                this.onComplete(selectedRole);
            }
        });

        // 標題與文字先淡出，避免干擾畫面
        tl.to([titleEl, selectedLabel], {
            opacity: 0,
            duration: 0.4,
            ease: "power2.out"
        }, 0);

        // 天空背景往下滑並淡出，露出底層 3D 星空
        tl.to(skyEl, {
            y: "100vh",
            opacity: 0,
            duration: 1.5,
            ease: "power2.inOut"
        }, 0.1);

        // 未被點選的幽浮跟隨天空同速往下並淡出
        tl.to(otherCards, {
            y: "100vh",
            opacity: 0,
            duration: 1.5,
            ease: "power2.inOut"
        }, 0.1);

        // 被點選的幽浮慢慢往下（速度比背景慢，形成進入星空的視差感）
        tl.to(selectedCard, {
            y: "100vh",
            scale: 0.92,
            opacity: 0.85,
            duration: 2.5,
            ease: "power1.inOut"
        }, 0.1);
    }

    destroy() {
        if (this.rootEl) {
            this.rootEl.remove();
            this.rootEl = null;
        }
    }

    resetAndShow() {
        if (!this.rootEl) {
            this.mount();
            return;
        }

        const skyEl = document.getElementById("skyBackdrop");
        const titleEl = this.rootEl.querySelector(".role-title");
        const allCards = Array.from(this.rootEl.querySelectorAll(".ufo-card"));
        const allLabels = Array.from(this.rootEl.querySelectorAll(".ufo-label"));

        // 先停止可能殘留的動畫
        gsap.killTweensOf([skyEl, titleEl, ...allCards, ...allLabels]);

        // 還原位置與縮放，但先將透明度設為 0 準備慢慢淡入
        gsap.set(skyEl, { y: 0, opacity: 0 });
        gsap.set(titleEl, { y: 0, opacity: 0 });
        gsap.set(allCards, { y: 0, scale: 1, opacity: 0 });
        gsap.set(allLabels, { opacity: 0 });

        this.isTransitioning = false;
        this.rootEl.style.pointerEvents = "none";
        this.rootEl.style.display = "flex";

        // 慢慢淡入天空與雙幽浮，避免畫面突然變亮
        gsap.to([skyEl, titleEl, ...allCards, ...allLabels], {
            opacity: 1,
            duration: 1.5,
            ease: "power2.out",
            onComplete: () => {
                this.rootEl.style.pointerEvents = "auto";
            }
        });
    }
}