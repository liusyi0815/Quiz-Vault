export class AuthModal {
    /**
     * @param {Object} options
     * @param {"student" | "teacher"} [options.role="student"]
     * @param {(payload: { role: "student" | "teacher", mode: "login" | "register", data: Object }) => void} [options.onSuccess]
     * @param {() => void} [options.onBackToRoleSelect]
     */
    constructor(options = {}) {
        this.role = options.role === "teacher" ? "teacher" : "student";
        this.mode = "login"; // "login" | "register"
        this.onSuccess = options.onSuccess || (() => {});
        this.onBackToRoleSelect = options.onBackToRoleSelect || null;
        this.rootEl = null;
    }

    mount(role = this.role) {
        this.role = role === "teacher" ? "teacher" : "student";
        this.mode = "login";

        if (!this.rootEl) {
            const html = `
                <div id="authLayer" class="auth-layer" data-role="${this.role}">
                    <div class="auth-card" id="authCard"></div>
                </div>

                <div id="teacherPendingModal" class="auth-pending-overlay" aria-hidden="true">
                    <div class="auth-pending-box">
                        <h3>申請已送出</h3>
                        <p>您的教師帳號已送交管理員審核。<br />審核通過後，系統將寄送通知至您的 Email。</p>
                        <button type="button" id="closePendingBtn" class="auth-submit-btn">確定</button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML("beforeend", html);
            this.rootEl = document.getElementById("authLayer");
            this.bindStaticEvents();
        } else {
            this.rootEl.dataset.role = this.role;
            this.rootEl.classList.remove("hidden");
        }

        this.render();
    }

    setRole(role) {
        this.role = role === "teacher" ? "teacher" : "student";
        this.mode = "login";
        if (this.rootEl) {
            this.rootEl.dataset.role = this.role;
            this.render();
        }
    }

    hide() {
        if (this.rootEl) {
            this.rootEl.classList.add("hidden");
        }
    }

    show() {
        if (this.rootEl) {
            this.rootEl.classList.remove("hidden");
        }
    }

    bindStaticEvents() {
        const pendingModal = document.getElementById("teacherPendingModal");
        const closePendingBtn = document.getElementById("closePendingBtn");

        closePendingBtn.addEventListener("click", () => {
            pendingModal.classList.remove("active");
            pendingModal.setAttribute("aria-hidden", "true");
            this.mode = "login";
            this.render();
        });
    }

    render() {
        const cardEl = document.getElementById("authCard");
        if (!cardEl) return;

        const isTeacher = this.role === "teacher";
        const isLogin = this.mode === "login";

        let headingText = "";
        let fieldsHTML = "";
        let submitText = "";
        let footerHTML = "";

        if (isLogin) {
            headingText = isTeacher ? "教師登入" : "學生登入";
            const idLabel = isTeacher ? "帳號" : "學號";
            const idPlaceholder = isTeacher ? "請輸入教師帳號" : "請輸入學號";
            submitText = "登入";

            fieldsHTML = `
                <div class="auth-field">
                    <label for="loginAccount">${idLabel}</label>
                    <input type="text" id="loginAccount" name="account" placeholder="${idPlaceholder}" autocomplete="username" required />
                </div>
                <div class="auth-field">
                    <label for="loginPassword">密碼</label>
                    <input type="password" id="loginPassword" name="password" placeholder="請輸入密碼" autocomplete="current-password" required />
                </div>
            `;

            footerHTML = `
                <div class="auth-footer">
                    <span>還沒有帳號嗎？</span>
                    <button type="button" id="switchToRegisterBtn" class="auth-link-btn">
                        ${isTeacher ? "申請註冊" : "建立一個"}
                    </button>
                </div>
            `;
        } else if (!isTeacher) {
            // 學生註冊
            headingText = "學生註冊";
            submitText = "建立帳號";

            fieldsHTML = `
                <div class="auth-field">
                    <label for="regStudentId">學號</label>
                    <input type="text" id="regStudentId" name="studentId" placeholder="例如：A11111111" required />
                </div>
                <div class="auth-field">
                    <label for="regName">姓名</label>
                    <input type="text" id="regName" name="name" placeholder="請輸入姓名" required />
                </div>
                <div class="auth-field">
                    <label for="regEmail">Email</label>
                    <input type="email" id="regEmail" name="email" placeholder="example@mail.edu.tw" required />
                </div>
                <div class="auth-field">
                    <label for="regPassword">密碼</label>
                    <input type="password" id="regPassword" name="password" placeholder="請設定密碼" required />
                </div>
                <div class="auth-field">
                    <label for="regConfirmPassword">確認密碼</label>
                    <input type="password" id="regConfirmPassword" name="confirmPassword" placeholder="請再次輸入密碼" required />
                </div>
            `;

            footerHTML = `
                <div class="auth-footer">
                    <span>已經有帳號了？</span>
                    <button type="button" id="switchToLoginBtn" class="auth-link-btn">返回登入</button>
                </div>
            `;
        } else {
            // 教師申請註冊
            headingText = "教師申請註冊";
            submitText = "送出申請";

            fieldsHTML = `
                <div class="auth-field">
                    <label for="teacherInviteCode">邀請碼（選填）</label>
                    <input type="text" id="teacherInviteCode" name="inviteCode" placeholder="若有邀請碼可填入" />
                </div>
                <div class="auth-field">
                    <label for="teacherUsername">帳號名稱</label>
                    <input type="text" id="teacherUsername" name="username" placeholder="請設定登入帳號" required />
                </div>
                <div class="auth-field">
                    <label for="teacherName">姓名</label>
                    <input type="text" id="teacherName" name="name" placeholder="請輸入姓名" required />
                </div>
                <div class="auth-field">
                    <label for="teacherSchool">任教學校</label>
                    <input type="text" id="teacherSchool" name="school" placeholder="請輸入任教學校" required />
                </div>
                <div class="auth-field">
                    <label for="teacherEmail">Email</label>
                    <input type="email" id="teacherEmail" name="email" placeholder="teacher@school.edu.tw" required />
                </div>
                <div class="auth-field">
                    <label for="teacherPassword">密碼</label>
                    <input type="password" id="teacherPassword" name="password" placeholder="請設定密碼" required />
                </div>
                <div class="auth-field">
                    <label for="teacherConfirmPassword">確認密碼</label>
                    <input type="password" id="teacherConfirmPassword" name="confirmPassword" placeholder="請再次輸入密碼" required />
                </div>
            `;

            footerHTML = `
                <div class="auth-footer">
                    <span>已經有帳號了？</span>
                    <button type="button" id="switchToLoginBtn" class="auth-link-btn">返回登入</button>
                </div>
            `;
        }

        const backRoleHTML = this.onBackToRoleSelect
            ? `<button type="button" id="backToRoleBtn" class="auth-back-role-btn">← 重新選擇身分</button>`
            : "";

        cardEl.innerHTML = `
            <div class="auth-header-row">
                <h2 class="auth-title">${headingText}</h2>
                ${backRoleHTML}
            </div>
            <form id="authForm" class="auth-form" novalidate>
                <div class="auth-fields-scroll">
                    ${fieldsHTML}
                </div>
                <p id="authErrorMsg" class="auth-error-msg" role="alert"></p>
                <button type="submit" class="auth-submit-btn">${submitText}</button>
            </form>
            ${footerHTML}
        `;

        this.bindFormEvents();
    }

    bindFormEvents() {
        const formEl = document.getElementById("authForm");
        const errorEl = document.getElementById("authErrorMsg");
        const toRegisterBtn = document.getElementById("switchToRegisterBtn");
        const toLoginBtn = document.getElementById("switchToLoginBtn");
        const backToRoleBtn = document.getElementById("backToRoleBtn");

        if (toRegisterBtn) {
            toRegisterBtn.addEventListener("click", () => {
                this.mode = "register";
                this.render();
            });
        }

        if (toLoginBtn) {
            toLoginBtn.addEventListener("click", () => {
                this.mode = "login";
                this.render();
            });
        }

        if (backToRoleBtn && this.onBackToRoleSelect) {
            backToRoleBtn.addEventListener("click", () => {
                this.onBackToRoleSelect();
            });
        }

        formEl.addEventListener("submit", (e) => {
            e.preventDefault();
            errorEl.textContent = "";

            if (this.mode === "login") {
                const account = document.getElementById("loginAccount").value.trim();
                const password = document.getElementById("loginPassword").value;

                if (!account || !password) {
                    errorEl.textContent = "請完整填寫帳號與密碼。";
                    return;
                }

                // 未來可在此替換為真實後端 API 呼叫
                this.onSuccess({
                    role: this.role,
                    mode: "login",
                    data: { account, password }
                });
                return;
            }

            if (this.role === "student") {
                const studentId = document.getElementById("regStudentId").value.trim();
                const name = document.getElementById("regName").value.trim();
                const email = document.getElementById("regEmail").value.trim();
                const password = document.getElementById("regPassword").value;
                const confirmPassword = document.getElementById("regConfirmPassword").value;

                if (!studentId || !name || !email || !password || !confirmPassword) {
                    errorEl.textContent = "請完整填寫所有註冊欄位。";
                    return;
                }
                if (password !== confirmPassword) {
                    errorEl.textContent = "兩次輸入的密碼不一致。";
                    return;
                }

                // 同步寫入 localStorage 方便銜接學生個人檔案
                localStorage.setItem("studentId", studentId);
                localStorage.setItem("studentName", name);

                this.onSuccess({
                    role: "student",
                    mode: "register",
                    data: { studentId, name, email }
                });
                return;
            }

            // 老師申請註冊
            const inviteCode = document.getElementById("teacherInviteCode").value.trim();
            const username = document.getElementById("teacherUsername").value.trim();
            const name = document.getElementById("teacherName").value.trim();
            const school = document.getElementById("teacherSchool").value.trim();
            const email = document.getElementById("teacherEmail").value.trim();
            const password = document.getElementById("teacherPassword").value;
            const confirmPassword = document.getElementById("teacherConfirmPassword").value;

            if (!username || !name || !school || !email || !password || !confirmPassword) {
                errorEl.textContent = "請完整填寫必填欄位（邀請碼為選填）。";
                return;
            }
            if (password !== confirmPassword) {
                errorEl.textContent = "兩次輸入的密碼不一致。";
                return;
            }

            const pendingModal = document.getElementById("teacherPendingModal");
            pendingModal.classList.add("active");
            pendingModal.setAttribute("aria-hidden", "false");
        });
    }
}