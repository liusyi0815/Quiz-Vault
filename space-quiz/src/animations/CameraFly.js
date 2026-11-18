import gsap from "gsap";

/**
 * 執行鏡頭飛入星球的動畫
 * @param {THREE.PerspectiveCamera} camera - 相機物件
 * @param {THREE.Mesh} targetMesh - 目標星球的 mesh
 * @param {Function} onComplete - 動畫播完後的回調函數
 */
export function flyToPlanet(camera, targetMesh, onComplete) {
    if (!targetMesh || !targetMesh.position) {
        console.error("flyToPlanet 錯誤：目標 mesh 不存在");
        if (onComplete) onComplete();
        return;
    }

    const targetPosition = targetMesh.position;
    
    // 計算停在星球前方的安全位置
    const endPosition = {
        x: targetPosition.x,
        y: targetPosition.y + 7,
        z: targetPosition.z + 9.5
    };

    const endLookAt = {
        x: targetPosition.x,
        y: targetPosition.y+8,
        z: targetPosition.z
    };

    const tl = gsap.timeline({
        onUpdate: () => {
            camera.lookAt(endLookAt.x, endLookAt.y, endLookAt.z);
        },
        onComplete: () => {
            if (onComplete) onComplete();
        }
    });

    tl.to(camera.position, {
        x: endPosition.x,
        y: endPosition.y,
        z: endPosition.z,
        duration: 1.5,
        ease: "power2.inOut"
    });
}