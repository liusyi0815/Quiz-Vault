import gsap from "gsap";

export function slidePlanets(planets, targetIndex) {
    planets.forEach((planet, index) => {
        // 1. 計算每個星球相對於當前目標星球的角度偏移
        // 數值 0.55 控制星球在軌道上的間距弧度
        const angle = (index - targetIndex) * 0.8;

        // 2. 利用三角函數計算 3D 軌道座標
        // X 軸：水平分佈（隨角度呈正弦曲線展開）
        const targetX = Math.sin(angle) * 50;
        
        // Z 軸：遠近深度（中心點在前方 z=0，左側/後方往後退變負，右側往前變正）
        const targetZ = (Math.cos(angle) - 1) * 10-15;
        
        // Y 軸：高低起伏（左側/中心微幅往上，右側往下，+ 4 是整體的基準高度）
        const targetY = Math.sin(angle) * 5 + 4;

        // 3. 計算縮放大小：當前被選中的中心星球最大 (1.2)，其餘依距離遞減
        const distanceAbs = Math.abs(index - targetIndex);
        const scaleValue = distanceAbs === 0 ? 1.8 : Math.max(0.6, 1 - distanceAbs * 0.15);

        // 4. 執行 GSAP 平滑動畫
        gsap.to(planet.mesh.position, {
            x: targetX,
            y: targetY,
            z: targetZ,
            duration: 1.2,
            ease: "power3.out"
        });

        gsap.to(planet.mesh.scale, {
            x: scaleValue,
            y: scaleValue,
            z: scaleValue,
            duration: 1.2,
            ease: "power3.out"
        });
    });
}