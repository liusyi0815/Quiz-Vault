import * as THREE from "three";

// 1. 建立科目旗幟
export function createFlag(subjectName) {
    const group = new THREE.Group();
    // 竿子
    const poleGeo = new THREE.CylinderGeometry(0.04, 0.04, 2.5, 8);
    const poleMat = new THREE.MeshStandardMaterial({ color: 0x8b5a2b });
    const pole = new THREE.Mesh(poleGeo, poleMat);
    pole.position.y = 1.25;
    group.add(pole);
    
    // 旗面 (使用 Canvas 產生文字)
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 64;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#89d8ff';
    ctx.fillRect(0, 0, 128, 64);
    ctx.fillStyle = '#000000';
    ctx.font = 'bold 26px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(subjectName, 64, 32);
    const texture = new THREE.CanvasTexture(canvas);
    const flagGeo = new THREE.PlaneGeometry(1.2, 0.6);
    const flagMat = new THREE.MeshBasicMaterial({ map: texture, side: THREE.DoubleSide });
    const flagMesh = new THREE.Mesh(flagGeo, flagMat);
    flagMesh.position.set(0.6, 2.1, 0);
    group.add(flagMesh);
    
    group.userData.type = "exam_flag";
    return group;
}

// 2. 建立書本（成績與解答 - 呈現微幅翻開的立體感）
export function createBook() {
    const group = new THREE.Group();
    
    // 讓整本書立起來並面向鏡頭微傾斜
    group.rotation.x = Math.PI / 5; 
    group.rotation.y = Math.PI / 10;

    // 書本封面/底座（咖啡色厚皮）
    const coverGeo = new THREE.BoxGeometry(1.6, 0.1, 1.2);
    const coverMat = new THREE.MeshStandardMaterial({ color: 0x8B4513, roughness: 0.6 });
    const cover = new THREE.Mesh(coverGeo, coverMat);
    group.add(cover);

    // 左頁（稍微傾斜）
    const pageGeo = new THREE.BoxGeometry(0.75, 0.12, 1.1);
    const pageMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.9 });
    
    const leftPage = new THREE.Mesh(pageGeo, pageMat);
    leftPage.position.set(-0.38, 0.06, 0);
    leftPage.rotation.z = -0.15; // 模擬翻開的微幅傾斜
    group.add(leftPage);

    // 右頁（反向傾斜）
    const rightPage = new THREE.Mesh(pageGeo, pageMat);
    rightPage.position.set(0.38, 0.06, 0);
    rightPage.rotation.z = 0.15;
    group.add(rightPage);

    group.userData.type = "exam_book";
    return group;
}

export function createAttendanceBook() {
    const group = new THREE.Group();
    
    // 讓整本書立起來並面向鏡頭微傾斜
    group.rotation.x = Math.PI / 5; 
    group.rotation.y = Math.PI / 10;

    // 書本封面/底座（咖啡色厚皮）
    const coverGeo = new THREE.BoxGeometry(1.6, 0.1, 1.2);
    const coverMat = new THREE.MeshStandardMaterial({ color: 0x1b365d, roughness: 0.6 });
    const cover = new THREE.Mesh(coverGeo, coverMat);
    group.add(cover);

    // 左頁（稍微傾斜）
    const pageGeo = new THREE.BoxGeometry(0.75, 0.12, 1.1);
    const pageMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.9 });
    
    const leftPage = new THREE.Mesh(pageGeo, pageMat);
    leftPage.position.set(-0.38, 0.06, 0);
    leftPage.rotation.z = -0.15; // 模擬翻開的微幅傾斜
    group.add(leftPage);

    // 右頁（反向傾斜）
    const rightPage = new THREE.Mesh(pageGeo, pageMat);
    rightPage.position.set(0.38, 0.06, 0);
    rightPage.rotation.z = 0.15;
    group.add(rightPage);

    group.userData.type = "exam_book";
    return group;
}

// 3. 建立鉛筆（將筆身、筆尖、金屬箍、橡皮擦組合成一個完美的整體）
export function createPencil() {
    const group = new THREE.Group();

    // 筆身 (黃色六角/圓柱體)
    const bodyGeo = new THREE.CylinderGeometry(0.12, 0.12, 1.6, 6);
    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xffcc00 });
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    group.add(body);

    // 筆尖木頭段 (原木色)
    const woodGeo = new THREE.ConeGeometry(0.12, 0.4, 6);
    const woodMat = new THREE.MeshStandardMaterial({ color: 0xDEB887 });
    const wood = new THREE.Mesh(woodGeo, woodMat);
    wood.position.y = -1.0;
    wood.rotation.z = Math.PI;
    group.add(wood);

    // 黑色筆芯尖端
    const tipGeo = new THREE.ConeGeometry(0.04, 0.15, 6);
    const tipMat = new THREE.MeshStandardMaterial({ color: 0x222222 });
    const tip = new THREE.Mesh(tipGeo, tipMat);
    tip.position.y = -1.22;
    tip.rotation.z = Math.PI;
    group.add(tip);

    // 金屬箍 (銀色)
    const ringGeo = new THREE.CylinderGeometry(0.125, 0.125, 0.15, 6);
    const ringMat = new THREE.MeshStandardMaterial({ color: 0xC0C0C0, metalness: 0.8 });
    const ring = new THREE.Mesh(ringGeo, ringMat);
    ring.position.y = 0.87;
    group.add(ring);

    // 橡皮擦 (粉紅色)
    const eraserGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.3, 6);
    const eraserMat = new THREE.MeshStandardMaterial({ color: 0xff9999 });
    const eraser = new THREE.Mesh(eraserGeo, eraserMat);
    eraser.position.y = 1.05;
    group.add(eraser);

    // 調整整支鉛筆的預設旋轉與傾斜角度（對齊手繪稿中斜插在星球上的姿態）
    group.rotation.z = -Math.PI / 6; 
    group.rotation.x = Math.PI / 12;

    group.userData.type = "exam_pencil";
    return group;
}

// 3-B. 建立老師版紅筆
export function createRedPencil() {
    const group = new THREE.Group();

    const redMat = new THREE.MeshStandardMaterial({
        color: 0xe53935,
        roughness: 0.45,
        metalness: 0.05
    });
    const bodyMat = new THREE.MeshStandardMaterial({
        color: 0xe6e4dc, // 淺米灰筆身
        roughness: 0.6,
        metalness: 0.05
    });
    const darkRedMat = new THREE.MeshStandardMaterial({
        color: 0xc62828,
        roughness: 0.5
    });

    // 整支筆沿 Y 軸排列（下方為筆蓋端，上方為筆尾端，方便沿用原本傾斜姿態）
    // 1. 淺灰筆身主體
    const bodyGeo = new THREE.CylinderGeometry(0.115, 0.115, 1.35, 16);
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    body.position.y = 0.15;
    group.add(body);

    // 2. 筆尾紅色尾塞
    const tailGeo = new THREE.CylinderGeometry(0.11, 0.115, 0.24, 16);
    const tail = new THREE.Mesh(tailGeo, redMat);
    tail.position.y = 0.94;
    group.add(tail);

    const tailCapGeo = new THREE.SphereGeometry(0.11, 16, 12, 0, Math.PI * 2, 0, Math.PI / 2);
    const tailCap = new THREE.Mesh(tailCapGeo, redMat);
    tailCap.position.y = 1.06;
    group.add(tailCap);

    // 3. 筆蓋與筆身交接環
    const capRingGeo = new THREE.CylinderGeometry(0.132, 0.132, 0.08, 16);
    const capRing = new THREE.Mesh(capRingGeo, darkRedMat);
    capRing.position.y = -0.52;
    group.add(capRing);

    // 4. 紅色筆蓋主體（略微向筆蓋端收窄）
    const capGeo = new THREE.CylinderGeometry(0.13, 0.105, 0.85, 16);
    const cap = new THREE.Mesh(capGeo, redMat);
    cap.position.y = -0.98;
    group.add(cap);

    // 筆蓋末端圓弧收尾
    const capTipGeo = new THREE.SphereGeometry(0.105, 16, 12, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2);
    const capTip = new THREE.Mesh(capTipGeo, redMat);
    capTip.position.y = -1.405;
    group.add(capTip);

    // 5. 筆蓋上的立體筆夾
    const clipBarGeo = new THREE.BoxGeometry(0.045, 0.68, 0.07);
    const clipBar = new THREE.Mesh(clipBarGeo, redMat);
    clipBar.position.set(0, -0.92, 0.155);
    clipBar.rotation.x = 0.04;
    group.add(clipBar);

    const clipTopGeo = new THREE.BoxGeometry(0.045, 0.14, 0.12);
    const clipTop = new THREE.Mesh(clipTopGeo, darkRedMat);
    clipTop.position.set(0, -0.62, 0.12);
    group.add(clipTop);

    // 調整整體傾斜角度，讓筆蓋朝下偏斜、筆尾朝上
    group.rotation.z = -Math.PI / 6;
    group.rotation.x = Math.PI / 12;

    group.userData.type = "exam_pencil";
    return group;
}
