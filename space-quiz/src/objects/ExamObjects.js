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

// 2. 建立書本（成績與解答）
export function createBook() {
    const group = new THREE.Group();

    // 書本封面基底
    const bookGeo = new THREE.BoxGeometry(1.5, 0.15, 1.1);
    const bookMat = new THREE.MeshStandardMaterial({ color: 0xd2b48c, roughness: 0.5 });
    const book = new THREE.Mesh(bookGeo, bookMat);
    group.add(book);

    // 立體書頁裝飾
    const pagesGeo = new THREE.BoxGeometry(1.4, 0.2, 1.0);
    const pagesMat = new THREE.MeshStandardMaterial({ color: 0xffffff });
    const pages = new THREE.Mesh(pagesGeo, pagesMat);
    pages.position.y = 0.05;
    group.add(pages);

    group.userData.type = "exam_book";
    return group;
}

// 3. 建立鉛筆（開始考試）
export function createPencil() {
    const group = new THREE.Group();

    // 筆身 (黃色)
    const bodyGeo = new THREE.CylinderGeometry(0.12, 0.12, 1.8, 6);
    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xffcc00 });
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    body.rotation.z = Math.PI / 4;
    group.add(body);

    // 筆尖 (黑色/木頭色)
    const tipGeo = new THREE.ConeGeometry(0.12, 0.4, 6);
    const tipMat = new THREE.MeshStandardMaterial({ color: 0x333333 });
    const tip = new THREE.Mesh(tipGeo, tipMat);
    tip.position.set(-0.7, -0.7, 0);
    tip.rotation.z = Math.PI / 4;
    group.add(tip);

    // 橡皮擦 (粉紅色)
    const eraserGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.3, 6);
    const eraserMat = new THREE.MeshStandardMaterial({ color: 0xff9999 });
    const eraser = new THREE.Mesh(eraserGeo, eraserMat);
    eraser.position.set(0.7, 0.7, 0);
    eraser.rotation.z = Math.PI / 4;
    group.add(eraser);

    group.userData.type = "exam_pencil";
    return group;
}